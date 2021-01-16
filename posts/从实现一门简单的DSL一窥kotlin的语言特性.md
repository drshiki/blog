## 从实现一门简单的DSL一窥kotlin的语言特性

我们项目里面使用一个定制过的ORM，查询语言使用JPQL，语句不但写起来非常长，而且IDE本身不提供对字符串语法的检查，参数设置也很繁琐，项目中充斥下面的代码

```
        em.createQuery("""select e0 from tk_Person e0 
            where e0.gender = :gender and e0.birthDay >= :fromDate and e0.birthDay <= :toDate 
            and (e0.name not like CONCAT('%', :name) or (e0.registerDate <= :toDate and e0.name like CONCAT('%', :name)))""", 
                Person::class.java)
                .setParameter("fromDate", fromDate)
                .setParameter("toDate", toDate)
                .setParameter("gender", "male")
                .setParameter("name", "明").resultList
```
其目的是筛选生日在fromDate和toDate之间男性，要求如果他的名字以“明”字结尾那么他的登记日必须小于toDate，如果不是，则没有此要求，类似这样的需求实在是司空见惯了

让我们来看看这段代码有什么问题：

1. 非常明显的，Java/kotlin IDE并不会对字符串做语法检查，最多就只做个拼写检查而已，所以如果你一时大意把代码写成了from写成form这样，IDE或者编译器是不会给你任何提示的，什么你说你是英语达人，过了CET 666级，雅思托福满分不会犯这种低级错误，太天真了，躲得过单词拼写，躲得过各种奇葩的表命名？？？括号匹配呢？？？

2. 然后是实参远离了它参与的比较运算，不能很直观看出这些参数的作用，setParameter这个方法是没有具体意义的

3. 冗余严重，为什么方法后已经写了第二个参数Person::class.java，但是程序员还是需要写tk_Person这样的东西呢，表名和类名是一一对应的，建立个mapping，写一遍多好，`select e0 from `这段也是多余的，因为只要是select所有字段，都要重复写这句，是不是很烦？

作为一个合格的攻城狮，怎么可以写这么重复的东西，而我要开始咏唱DRY（Don't repeat yourself）咒语了

好吧，现在利用kotlin来改造一下上面的写法，呃，先抛出最后的效果

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

是不是第一眼非常简洁？

来看看这个DSL的代码，首先这个DSL不用你写太多的JPQL字符串了，简洁！

再者，参数直接和比较符放在一起，可读性也更强了，参数作用直观

也不用担心那些例如括号匹配错误之类的事情，毕竟DSL使用的花括号可以享用kotlin的语法检查，多棒！

而这，使用kotlin实现起来简直简单到爆，核心部分也就60行左右

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

让我们来展开说说这段kotlin的代码，从而展示一部分kt的特性

kotlin不用像Java那样需要预先定义某个函数式接口才能作为lambda的类型去使用，而是可以直接在使用处声明就可以使用了，而kt还支持将R Funtion<T, R>#apply(T t)这样的接口进一步写成T.() -> R，这样在lambda内部就不用显式指定对象名调用T本身的函数了
```
fun func(x: Int, f: (Int) -> String): String = f.invoke(x)
// 需要显式指定
func(1, {it -> it.toString()})
// 而如果写成这样，// 则不用写出来it
fun func(x: Int, f: Int.() -> String): String = f.invoke(x)
func(1, { toString() })
```
这个有什么用？参考DSL实现代码load方法最后一个参数里面and，or方法的调用就是依赖这个特性才不用写出it的

然后kotlin还支持将最后一个函数（或者lambda）类型的参数移出小括号外面，这个语法就骚了，相信用过groovy或者js的童鞋对这种写法已经相当熟悉了，而这个特性可是gradle dsl实现的重要语法基础，有了这个特性你就可以做到下面这样
```
fun func(x: Int, y: Int, f: (Int, Int) -> Int): Int = f.invoke(x, y)
// 调用的时候你就可以将
func(1, 2, {xv, yv -> xv + yv})
// 写成下面这样
func(1, 2) {xv, yv -> xv + yv}
```
在DSL的实现中就是例如使用load的时候传递最后一个参数的写法

kotlin还支持定义中缀函数，例如我们要给编译器加一个优化代码的方法，对于中缀函数的调用，kt允许你省略.和()，不过也有局限性，例如只支持一个参数，中缀函数实践中可以结合着接下来要讲的扩展函数来用
```
class Kotlinc {
    infix fun optimize(code: String): String =  "optimized $code"
}
// 调用方式
Kotlinc() optimize "\"print hello\""
```

要说到kt一个比较有名的特性了，扩展函数，这个也是kt替换Java静态工具类的一个优雅实践，其实也可以一定程度替换掉Java里面那种装饰器的作用

例如要给String类添加一个concat方法，我们可以这么做
```
fun String.concat(s: String): String = "$this$s"
```
这种比添加一个StringUtils优雅很多，实践中也有很多结合中缀函数用来做测试的，例如order.code shouldEquals "O_0001"这样的写法，语义非常直观，而上面也同样用来实现了le，eq这样的类似操作符的作用

这次随便挑了几个kotliner日常使用频率极高的特性来说，并没有很有规划性的选择，其实我更想对比Java来说一说reified generics和coroutine这些东西的，但这次就算了，毕竟你确实只要懂上面几个说的特性就能给你们组的SQL组装写出这样的DSL了，比SQLBuilder好用多了不是？然后如果更巧你们组用的gradle，那么现在看他构建脚本那魔性的语法是不是也不痛苦了

再说回这个DSL实现，很多必要的东西都没有，但是你都看了这篇文章了，已经是一个成熟的kotliner了，要学会自己去改进不是。喜欢动手的还可以将le改成\`<=\`这样的形式，不支持select，join，on，having，加节点类型不就行了，利用DslMarker完善作用域的隔离之类的，自己去探索吧
