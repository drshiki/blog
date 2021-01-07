## 从实现一门简单的DSL一窥kotlin的语言特性

我们项目里面使用一个定制过的ORM，查询语言使用JPQL，语句不但写起来非常长，而且IDE本身不提供对字符串语法的检查，参数设置也很繁琐，项目中充斥下面的代码

```
    em.createQuery("select e0 from tk_Person e0 "+
            "where e0.gender = :gender "+
            "and e0.birthDay >= :fromDate and e0.birthDay <= :toDate "+
            "and ( "+
            “ e0.name not like CONCAT('%', :name) "+
            " or (e0.registerDate <= :toDate and e0.name like CONCAT('%', :name)) "+
            ")", Person::class.java)
            .setParameter("fromDate", fromDate)
            .setParameter("toDate", toDate)
            .setParameter("gender", "male")
            .setParameter("name", "明")
            .resultList
```
其目的是筛选生日在fromDate和toDate之间男性，要求如果他的名字以“明”字结尾那么他的登记日必须小于toDate，如果不是，则没有此要求，类似这样的需求实在是司空见惯了

让我们来看看这段代码有什么问题：

1. 非常明显的，Java/kotlin IDE并不会对字符串语法做检查，最多就只做个拼写检查而已，所以如果你一时错手把代码写成了`select e0 form `这样，IDE或者编译器是不会给你任何提示的，什么你说你是英语达人，CET 666+？不会犯这种低级错误，太天真了，躲得过单词拼写，你躲得过各种奇葩的表命名？？？括号匹配呢？？？

2. 使用的实参远离了它参与的比较运算，不能很轻松看出这些参数的作用，setParameter这个方法是没有具体意义的

3. 冗余严重，为什么方法后已经写了第二个参数Person::class.java，但是程序员还是需要写tk_Person这样的东西呢，表名和类名是一一对应的，建立个mapping，写一遍多好，`select e0 from `这段也是多余的，因为无论我查哪个表，都要重复写这句，巨烦

好吧，来我们利用kotlin来改造一下上面的写法，呃，先抛出最后的效果

```
    load(em, Person::class.java)  {
        and {
            "gender" eq  "male"
            "birthDate" ge fromDate
            "birthDate" le toDate
            or {
                not {"name" ends "明"}
                and {
                    "registerDate" le toDate
                    "name" ends "明"
                }
            }
        }
    }
```

来看看这个DSL的代码，首先这个DSL不用你写太多的JPQL字符串了，再者，参数直接和比较符放在一起，可读性也更强了，也不用担心那些例如括号匹配错误之类的事情，毕竟DSL使用的花括号可以享用kotlin的语法检查，多棒！而这，使用kotlin实现起来简直简单到爆，核心部分也就60行左右。

下面给出实现代码，当然这里不会只有核心部分，还包括了一些拼接和获取参数的方法，所有会长一些

```
    fun load(em: EntityManager, cls: Class<out BaseEntity>,
                conditionBlock: Condition.() -> Condition):
            MutableList<out BaseEntity> {
        val mTools = AppBeans.get(MetadataTools::class.java)
        val condition = Condition(Triple(Condition.NULL_PLACE_HOLDER, Condition.NULL_PLACE_HOLDER, Condition.NULL_PLACE_HOLDER))
        val invoke = conditionBlock.invoke(condition)

        var conditionQl = invoke.metaExpr.third
        var query = "select e0 from ${mTools.getEntityName(cls)} e0 "

        // add Where Clause
        query += if (conditionQl != "")
            " where $conditionQl" else ""

        val emQuery = em.createQuery(query, cls)
        params.forEachIndexed { idx, p -> emQuery.setParameter(idx, p) }

        return emQuery.resultList
    }

    class Condition(var metaExpr: Triple<String, Any, String>) {

        val subConditions: MutableList<Condition> by lazy { mutableListOf() }
        val on: MutableList<On> by lazy { mutableListOf() }

        companion object {
            val NULL_PLACE_HOLDER: String = ""
        }

        val Any?.unit get() = Unit

        infix fun String.eq(v: Any): Unit = _eq(this, v).unit

        infix fun String.le(v: Any): Unit = _le(this, v).unit

        infix fun String.ge(v: Any): Unit = _ge(this, v).unit

        infix fun String.ends(v: Any): Unit = _endsWith(this, v).unit

        private fun _eq(field: String, value: Any) =
                subConditions.add(Condition(Triple(field, value, "${if (field.contains(".")) field else "e0.$field"} = :param")))

        private fun _le(field: String, value: Any) =
                subConditions.add(Condition(Triple(field, value, "${if (field.contains(".")) field else "e0.$field"} <= :param")))

        private fun _ge(field: String, value: Any) =
                subConditions.add(Condition(Triple(field, value, "${if (field.contains(".")) field else "e0.$field"} >= :param")))

        private fun _endsWith(field: String, value: Any) =
                subConditions.add(Condition(Triple(field, value, "${if (field.contains(".")) field else "e0.$field"} like CONCAT ('%', :param)")))

        fun or(conditionBlock: Condition.() -> Unit): Condition {
            val c = Condition(Triple(NULL_PLACE_HOLDER, NULL_PLACE_HOLDER, NULL_PLACE_HOLDER))
            conditionBlock.invoke(c)
            val s = c.subConditions.joinToString(" or ") { "(${it.metaExpr.third})" }
            c.metaExpr = Triple(NULL_PLACE_HOLDER, NULL_PLACE_HOLDER, s)
            subConditions.add(c)
            return c
        }

        fun and(conditionBlock: Condition.() -> Unit): Condition {
            val c = Condition(Triple(NULL_PLACE_HOLDER, NULL_PLACE_HOLDER, NULL_PLACE_HOLDER))

            conditionBlock.invoke(c)
            val s = c.subConditions.joinToString(" and ") { "(${it.metaExpr.third})" }
            c.metaExpr = Triple(NULL_PLACE_HOLDER, NULL_PLACE_HOLDER, s)
            subConditions.add(c)
            return c
        }

        fun not(conditionBlock: Condition.() -> Unit): Condition {
            val c = and(conditionBlock)
            val s = "not " + c.metaExpr.third
            c.metaExpr = Triple(NULL_PLACE_HOLDER, NULL_PLACE_HOLDER, s)
            return c
        }

        fun getParams(): List<Any> {

            if (subConditions.size == 0) {
                return mutableListOf(metaExpr.second)
            }
            val params = mutableListOf<Any>()
            subConditions.forEach {
                params.addAll(it.getParams())
            }
            return params
        }
    }
```

但是必须说明的是，这个DSL过于简单，没有实现join on语法，没有聚合函数等等，而且条件嵌套一多起来就会出现lisp一样括号地狱，缩进也很多，但是呢，这并不妨碍我们本着学习的目的造一门语言来自得其乐，在自己负责的一亩三分地里面做个DSL解决一部分重复的问题，确认无疑，coder的快乐就是这么简单！

让我们来展开说说这段kotlin的代码

待续。。。
