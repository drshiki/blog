1. 
单例bean A依赖原型bean B时如果使用的是自动注入，那么每次从A访问B时获取到仍然是一样的B，因为A只有被创建的时候才会被重新设置B，对于单例来说只会被创建一次所以B也只会被设置一次

解决办法1：

继承ApplicationContextAware，每次访问B都调用context.getBean(B.class)使用即可

代码参考：
```
import org.springframework.beans.BeansException;
import org.springframework.context.ApplicationContext;
import org.springframework.context.ApplicationContextAware;

public class CommandManager implements ApplicationContextAware {

    private ApplicationContext applicationContext;

    public Object process(Map commandState) {
        // grab a new instance of the appropriate Command
        Command command = createCommand();
        // set the state on the (hopefully brand new) Command instance
        command.setState(commandState);
        return command.execute();
    }

    protected Command createCommand() {
        // notice the Spring API dependency!
        return this.applicationContext.getBean("command", Command.class);
    }

    public void setApplicationContext(
            ApplicationContext applicationContext) throws BeansException {
        this.applicationContext = applicationContext;
    }
}
```
解决办法2：

Lookup方法注入

代码参考：
```
public abstract class CommandManager {

    public Object process(Object commandState) {
        Command command = createCommand();
        command.setState(commandState);
        return command.execute();
    }

    @Lookup("myCommand")
    protected abstract Command createCommand();
}
```
@Lookup修饰的方法格式要求为
`<public|protected> [abstract] <return-type> theMethodName(no-arguments)`

参考连接：https://docs.spring.io/spring/docs/5.0.14.RELEASE/spring-framework-reference/core.html#beans-factory-method-injection

2. 
适配器模式适用于客户端期待的接口和原有的接口不同的时候，建一个适配器继承自客户端期待的接口，在客户端期待接口的实现上调用原有类的方法，所以适配器持有一个原来类

代码参考：
```
// 客户端期待的LogToDb接口
interface LogToDb {
    void log(String log);
}
// 原有接口LogToFile接口
interface LogToFile {
    void log(String log， String filename);
}
// 建一个适配器继承客户端期待的接口
class LogToDbImpl implements LogToDb {
    private LogToFile logToFileImpl; //logToFileImpl也可以采取让客户端自己set进去而非硬编码，可以实现动态指定最终委托的实现
    public void log(String log) {
        logToFileImpl.log(log, "defaul.log");
    }
}
// 补充一个Java FutureTask的适配器例子
public class FutureTask<V> implements RunnableFuture<V> {
    // Callable 客户期待的接口
    private Callable<V> callable;
    // Runnable 原有的接口
    public FutureTask(Runnable runnable, V result) {
        this.callable = Executors.callable(runnable, result);
        this.state = NEW;       // ensure visibility of callable
    }
}

public class Executors {
    public static <T> Callable<T> callable(Runnable task, T result) {
        if (task == null)
            throw new NullPointerException();
        // 适配器，包装了原有的接口，返回一个客户期待的接口
        return new RunnableAdapter<T>(task, result);
    }
}
// 适配器
static final class RunnableAdapter<T> implements Callable<T> {
    final Runnable task;
    final T result;
    RunnableAdapter(Runnable task, T result) {
        this.task = task;
        this.result = result;
    }
    public T call() {
        task.run();
        return result;
    }
}
```
参考链接：https://developer.ibm.com/zh/technologies/java/articles/j-lo-adapter-pattern/

3. 
spring IoC市面上似乎暂时只有一个替代品，Google guice，比spring快，而IoC是实现依赖倒置的一种思路，依赖注入只是spring用来实现IoC的一种办法而已，控制反转的意思将“因为底层类修改导致上层类要同步修改”这个东西解决掉，将控制权交回给上层，而不是底层牵动上层修改

代码参考：
```
class Car {
    private Wheel wheel = new Wheel();
}
class  Wheel {}
// 因为需求变动，Wheel要设计为动态支持size的，所以需要修改构造器
class  Wheel {
    public Wheel(int size) { ... }
}
// Car类也要修改
class Car {
    private Wheel wheel;
    public Car(int size) {
        this.wheel = new Wheel(size)
    }
}
// 而依赖链可能很长，几千个类，这种底层改一下，上层所有都要改，牵一发而动全身
// 同时违反创建和使用的职责分离原则
// 将创建的职责分离出来给IoC容器
class Car {
    private Wheel wheel;
    // 接收IoC容器注入
    setWheel(Wheel wheel) { ... }
}
class IoC {

}
```
参考链接：https://www.zhihu.com/question/23277575 @Mingqi的回答

4. 
ThreadLoca的线程隔离属性可以用来方便实现单个请求的被动缓存，例如在一个请求里面可能发生使用同样参数请求1000次同一个接口，这个时候可以用ThreadLocal保存第1次的结果给后面99次用，但是遇到tomcat线程复用会发生问题，所以需要在拦截器中清理，或继承HandlerInterceptorAdapter或实现HandlerInterceptor
```
// ConcurrentHashMap.key 方法名 + 参数值
// ConcurrentHashMap.value 返回值
// CacheAspect 检查是否已经缓存缓存的切面
public class CacheAspect {
    private static ThreadLocal<ConcurrentHashMap<String, Object>> threadLocal = ThreadLocal.withInitial(ConcurrentHashMap::new);
    public static remove() {
        threadLocal.remove()
    }
}
// 拦截器清理
public class CacheCleanInterceptor implements HandlerInterceptor {
    public void afterCompletion(final HttpServletRequest httpServletRequest, final HttpServletResponse httpServletResponse, final Object o, final Exception e) throws Exception {
    CacheAspect.remove();
  }
}
```
参考链接：https://blog.csdn.net/liufeixiaowei/article/details/78685350

5. 
WeakReference适合用在对那些可有可无的对象引用上，例如对于一个监控运行时bean的监控程序来说，业务中还存在的bean才需要监控，已经被回收的对象（也说已经没有强引用关联的对象）也就不需要再去监控了，所以监控器上的应该使用弱引用来引用对象   

6. 
ThreadLocal的结构图：
```
                            |---- table[1]:Entry --- threadLocal1: WeakReferenced -> value1
thread --- threadLocalMap---|---- table[2]:Entry --- threadLocal2: WeakReferenced -> value2
                            |---- table[3]:Entry --- threadLocal3: WeakReferenced -> value3
```
所以threadLocal如果已经被回收，那么在table中的key也会被回收掉，而value是被table[i].value强引用的，所以会被保留一段时间，直至ThreadLocal.set方法再被调用触发rehash后调用以下代码清理value，WeakHashMap经常用作缓存，使用的时候也可以参考ThreadLocal的设计，不然可能会因为value一直存在无法释放造成OOM

代码参考：
```
// ThreadLocal.java #712
private void expungeStaleEntries() {
    Entry[] tab = table;
    int len = tab.length;
    for (int j = 0; j < len; j++) {
        Entry e = tab[j];
        // 如果entry不为空但是key为空的时候，调用expungeStaleEntry
        if (e != null && e.get() == null)
            expungeStaleEntry(j);
    }
}
```
7. 
BigDecimal不推荐使用equals比较，因为equals会比较value和scale，推荐使用compareTo进行比较

8. 
Integer不推荐使用==比较，因为==只在Integer的值为-128-127的时候有效

9. 
HashMap，注意一下targetBinCount是指put值时候的目标桶的总数

- targetBinCount > TREEIFY_THRESHOLD(8) && table.length < MIN_TREEIFY_CAPACITY(64) 扩容

- targetBinCount <= TREEIFY_THRESHOLD(8) && table.length < MIN_TREEIFY_CAPACITY(64) 无特殊处理

- targetBinCount > TREEIFY_THRESHOLD(8) && table.length >= MIN_TREEIFY_CAPACITY(64) 变红黑树

- targetBinCount <= TREEIFY_THRESHOLD(8) && table.length >= MIN_TREEIFY_CAPACITY(64) 无特殊处理

代码参考：
```
public class CustomKey {

    public Object k;

    public CustomKey(Object k) {
        this.k = k;
    }

    // 确保在桶中遍历比较时能能找到对应的key
    @Override
    public boolean equals(Object obj) {
        if (obj instanceof CustomKey) {
            return k.equals(((CustomKey) obj).k);
        }
        return false;
    }

    // 保持hashCode一致，确保能int类型的key能放进一个桶，string类型能放进另一个桶
    @Override
    public int hashCode() {
        if (k instanceof Integer) {
            return 1;
        } else {
            return 0;
        }
    }
}
// 测试代码
HashMap<CustomKey, String> map = new HashMap<>(16, 0.75f);
for (int i = 0; i < 9; i++) {
    map.put(new CustomKey(i), "key"+i);
}
```
参考链接：HashMap.putVal实现

10.
 Thread的interrupt()方法的作用会将将线程从sleep, wait, join等状态唤醒并抛出异常，但是仍然需要程序员自己写while去检测Thread.interrupted()的状态决定是否退出
 
 Thread的interrupted()用于清除标识位，一般用于接收到中断设置后，线程选择持续执行的场景，所以需要清除标识位以便下一次检测能正常进行

 在InterruptedException被捕获后中断标识会自动被清除

FutureTask.cancel和ThreadPoolExecutor.shutdownNow都是调用Thread的interrupt()方法

代码参考：
```
    @Test
    public void test() {
        System.out.println("start running thread1");
        long thread1StartTime = System.currentTimeMillis();
        Thread t2 = new Thread(new Runnable() {

            @Override
            public void run() {
                System.out.println("start running thread2");
                long thread2StartTime = System.currentTimeMillis();
                try {
                    boolean entered = false;
                    while ((System.currentTimeMillis() - thread2StartTime) / 1000 < 5) {
                        long interval = (System.currentTimeMillis() - thread2StartTime) / 1000;
                        if (!entered && interval == 3) {
                            System.out.println("interrupt flag was true, interrupt=" + Thread.currentThread().isInterrupted());
                            entered = true;
                        }
                    }
                    Thread.sleep(1000);
                } catch (InterruptedException e) {
                    System.out.println("interrupt flag was cleared, interrupt=" + Thread.currentThread().isInterrupted());
                    System.out.println("invoked in sleeping");
                }
                System.out.println("end running thread2");
            }
        });
        t2.start();
        try {
            Thread.sleep(1000);
        } catch (InterruptedException e) {
            e.printStackTrace();
        }
        t2.interrupt();
        t2.join();
        long e = System.currentTimeMillis();
        double v = (e - thread1StartTime) / 1000.0;
        System.out.printf("end running thread1 after %f s", v);
    }

输出如下：
start running thread1
start running thread2
interrupt flag was true, interrupt=true
interrupt flag was cleared, interrupt=false
invoked in sleeping
end running thread2
end running thread1 after 5.001000 s
Process finished with exit code 0
```

 参考链接：https://www.zhihu.com/question/41048032 @ntopass的回答

11. 
同步异步是一种通信方式的分类方法，主要区别在于消息接收者是如何获知消息的，如果接收者要主动去获取消息的就是同步，如果是发送者通知或者拉起接收者那么就是异步，典型场景例如函数f调用函数g，f一直在调用点原地等待g的返回值，这个f一直在轮询这个点是不是有东西返回了，这个就是同步

12. 
volatile提供了一个轻量的同步机制的意思是，volatile变量可以作为某种标识，让关心的人（接收者）不断查询这个变量的状态来决定下一步干什么，比较适合用用来做控制型变量

- volatile适用于那种单一修改线程，多读取线程的场景
- 运算不依赖于volatile修饰的前值

13. 
Java的 AS IF 语义确保了线程内表现为i串行（实际上是不是串行没有关系）

14. 
volatile禁止重排序的作用，禁止重排序的实现是依靠内存屏障实现的，通过在给volatile变量赋值指令后加入lock add 0， %esp，lock的语义即将所有cpu中的cache的变量回写到主存，并使得所有cache失效，故也可以推测使用volatile的频繁写会导致JVM插入大量的内存屏障从而使得缓存cache高频失效，性能降低，而读只要求读取时的操作必须是连续的load->use，并不会产生对其他cpu缓存的影响，所以性能消耗比较低

代码参考：
```
public class DoubleCheckedLocking {                     //1
	
   private static Instance instance;                    //2
	
 
	
   public static Instance getInstance() {               //3
	
       if (instance == null) {                          //4: 第一次检查
	
           synchronized (DoubleCheckedLocking.class) {  //5: 加锁
	
               if (instance == null)                    //6: 第二次检查
	
                   instance = new Instance();           //7: 问题的根源出在这里
	
           }                                            //8
	
       }                                                //9
	
       return instance;                                 //10
	
   }                                                    //11
	
}                                                       //12

语句7进一步可以展开为以下过程

memory = allocate();   //7.1：分配对象的内存空间
	
ctorInstance(memory);  //7.2：初始化对象
	
instance = memory;     //7.3：设置 instance 指向刚分配的内存地址
```
错误时序：

- 线程1：执行完7.3，尚未执行7.2
- 线程2：执行4，发现instance不为空
- 线程2：执行10

此时返回了一个尚未初始化完成的对象

参考链接：https://www.zhihu.com/question/56606703/answer/1446275583

15. 
Java内存模型特性和语法支持

| 语法/特性 | 原子性 | 可见性 | 有序性 |
| ----- | ------ | ------ | ------ |
| synchronized | √ | √ | √ |
| volatile | | √ | √ |
| final | | √ | |

16. 
并发所指的一段时间内多个任务被执行，并行是一个时间点上多个任务被执行，所以并行一定是并发，但是并发不一定是并行

17. 
HashMap的容量总是2的幂，如果设置的初始容量不是2的幂，那么将会转换成大于初始容量的最邻近的2的幂

在保证n是2的幂的情况下，`(n - 1) & hash`可以实现取余运算。这个也是HashMap中用来定位桶的算法

HashMap中的扰动函数`(key == null) ? 0 : (h = key.hashCode()) ^ (h >>> 16)`，避免只能取低位特征造成高冲突，无符号右移16位作自异或运算可以提取高位特征加大随机性，降低冲突

参考链接：HashMap.tableSizeFor实现，HashMap.hash实现，https://www.zhihu.com/question/20733617 @胖君的回答

18. 
使用ReentrantLock替代synchronized加锁，前者
- 可实现公平锁（先到先获得锁，排队） new ReentrantLoc(true)
- 可绑定多条件
- 等待可中断 lock.tryLock(10, TimeUnit.SECONDS)

synchronized仅仅具有可重入，非公平的特征

19. 
实现线程安全的办法：
- 互斥同步，例如ReentrantLock，synchronize
- 非阻塞同步，CAS，乐观锁
- 无同步方案，如纯函数，ThreadLocal

20. 
自适应自旋锁是jvm默认开启的，目的是避免短时间内的锁切换导致频繁的线程恢复和操作系统内核态的切换，用忙循环去等待而不是休眠等待一个锁的释放

21. 
事务传播行为有7种，主要用于定义callee在一个已经开或未开事务中的caller中的行为，注意到其修饰的是callee：

| 传播行为 | 是否使用事务 | 继续caller事务/新开事务 | 场景/备注 |
| ----- | ------ | ------ | ------ |
| REQUIRED | √ | √/√ | spring默认，callee发生异常，即使caller catch处理，caller的事务仍然会回滚 |
| REQUIRES_NEW | √ | ×/√ | |
| NESTED | √ | - | 如果当前存在事务，则在嵌套事务内执行。如果当前没有事务，则执行与PROPAGATION_REQUIRED类似的操作，callee发生异常，caller catch处理，caller的事务会成功但callee事务会回滚 |
| MANDATORY | √ | √/× | caller没开事务则抛出异常 |
| SUPPORTS | √/× | √/× | |
| NOT_SUPPORTED | × | ×/× | |
| NEVER | × | ×/× | caller已开事务则抛出异常 |

22. 
MySQL的默认隔离级别为可重复读，Oracle，SqlServer中都是选择读已提交，spring默认隔离级别为default，随数据库隔离级别
数据库隔离级别：
| 隔离级别 | 脏读 | 可重复读 | 幻读 | 场景 |
| ----- | ------ | ------ | ------ | ----- |
| 读未提交 | √ | √ | √ | 用来做监控查看插入进度（debug）|
| 读已提交 | × | √ | √ | |
| 可重复读 | × | × | √ | |
| 可串行化 | × | × | × ||
可重复读于update而言，幻读于insert/delete而言

23. 
MySQL设置默认级别为可重复读而不是读已提交的原因是读已提交主从复制的底层机制依赖binlog的bug，但5.1后已修复此问题，会导致主从数据不一致
解决办法是：
1. 用可重复读
2. 使用row格式的binlog复制

参考链接：https://www.cnblogs.com/rjzheng/p/10510174.html

24. 
可重复读锁太多，会将其他事务的修改block住，条件列未命中索引可重复读会锁表，读已提交只会锁行

25. 
CopyOnWriteArrayList是线程安全的，每次新加数据，都会生成一个新数组new Object[lengyh+1]，使用可冲入锁保证线程安全，使用System.arraycopy保证复制的高效

26. 
Arraylist指定位置插入，可以立即定位插入位置，然后需要调整之后的元素
LinkedList指定位置插入需要先遍历，然后调整指针
Arraylist相比LinkedList的优势是支持高效随机访问，get(i)
经常需要指定位置插入add(e, i)的使用LinkedList，需要支持高效随机访问的用Arraylist
LinkedList在1.6的时候使用循环双向链表，1.7改成非循环的双向链表了

1.6
```
   |----------------------------------|
   |       |----------↓    |---------↓↓
|pre|null|next|  |pre|e1|next|  |pre|e2|next|
     ↑↑-----------|    ↑----------|      |
     |-----------------------------------|
```
1.7
```
                   last-------------|
           |---------↓    |--------↓↓
|pre|e1|next|  |pre|e2|next|  |pre|e3|next|
     ↑↑-----------|    ↑--------|      
     |----------first
```
1.7 的链表结构更清晰
1.7 的数据结点不会有null冗余结点，1.6的header结点data域总是为null的
1.6 采用头插addBefore(e, header)，1.7采用尾插linkLast(e)

27. 
ReentrantLock的公平锁和非公平锁的实现区别在于，前者来到时首先检查队列是否有排队线程，后者首先直接尝试获取锁，获取失败后二次尝试获取，失败后才入队

参考NonfairSync和FairSync的lock方法实现

桥接模式
例如
```
interface Rule {
    void dealData(DataContext dataContext);
}
class AmountRule implements Rule {}
class QuantityRule implements Rule {}
class ReportService {
    Rule rule;
    void generateReport(Rule rule, DataContext dataContext) {
        rule.dealData(dataContext)
    }
}
// 桥接,rule就好像是各个厂商的jdbc驱动，由他们各自实现
new ReportService(new AmountRule(), d)
new ReportService(new QuantityRule(), d)
```

28. 
redis对比zk，redis不适合做分布式锁，原因有如下



- redlock算法问题
- 需要轮询（即使是redison也是客户端轮询的），zk可以采用watch异步机制

1. 客户端1在Redis的master节点上拿到了锁
2. Master宕机了，存储锁的key还没有来得及同步到Slave上
3. master故障，发生故障转移，slave节点升级为master节点
4. 客户端2从新的Master获取到了对应同一个资源的锁

于是，客户端1和客户端2同时持有了同一个资源的锁。锁的安全性被打破了。

zookeeper适合做分布式锁，不适合做服务注册中心，redis不适合做分布式锁，kafka适合做高吞吐量的web场景，rabbitMQ适合做IM

29. 
有以下记录
| id | value |
| ----- | ------ |
| 1  | 1 |

| clientA | clientB | clientC |
| ----- | ------ | ------ |
| tx A begin|||
| select value from table where id = 1 => 1 |||
|| update table set value = value + 1 where id = 1 => value = 2 (单语句事务) ||
| select value from table where id = 1 => 1 (可重复读，快照读) |||
| update table set value = value + 1 where id = 1 => value = 3 (发生当前读，加行锁) </br> inodb 先读后改，读是 select 最新的 value = 2，基于2的值加1，利用当前读防止B的修改丢失 || tx C begin |
||| update table set value = value + 1 where id = 1 => lock wait B事务加行锁防止C基于B（B最后可能回滚）的修改结果去update|
| tx A commit |||
||| updated => value = 4 |
||| tx C commit |

参考链接：https://juejin.im/post/6844904180440629262，https://www.cnblogs.com/yuzhuang/p/11585774.html

30. 
binlog是数据库层面的日志（无论用什么存储引擎都会有），有语句形式，行形式，混合形式，用于主从复制和数据恢复，性能攸关的参数为`sync_binlog`调节日志刷盘时机，取值范围0到N，0时由系统自己选择（不可靠），1为单事务commit时刷（最慢最安全），N表示每N个事务commit后刷（最快最不安全），1为mysql默认设置，大一些可能会发生数据丢失，但是可以提高性能

redo，undo log是存储引擎层面的日志

redo是页面更新的diff，是物理log

undo log是数据库row的更新历史，是逻辑log，可以用来构造版本链

statement-based binlog和read committed组合产生bug的原因

| id | value |
| ----- | ------ |
| 1  | 1 |

| clientA | clientB |
| ----- | ------ |
| tx A begin||
|| tx B begin |
| delete from table where id < 5 => 删掉了id=1的记录 ||
|| insert into table value(2,1) => 插入一条id=2的记录 |
|| tx B commit |
| tx A commit ||
在主库上`select * from table`查询结果为
| id | value |
| ----- | ------ |
| 2  | 1 |
在从库存上`select * from table`查询结果为空

MySQL按事务提交的顺序而非每条语句的执行顺序来记录二进制日志，所以从库收到的binlog顺序是先插入后删除，而在主库上的顺序为先删除后插入

31. 
redlock算法过程

1. 记录当前时间T1
2. 分别在各个主redis上加锁
3. 当在 >N/2 +1 个节点上加锁成功时，表示获取锁成功
4. 获取时间T2， 重新计算锁的过期时间=原来锁的过期时间 -（T2-T1）

补充说明，如果加锁失败需要及时释放锁，加锁超时应该设置远小于锁过期时间例如10s锁过期，50毫秒等待超时，不然造成等待锁过长，而已经持有的锁占用太久导致系统卡住，假设存在ABCDE五台机器，

|时间| t1 | t2 | t3 | t4 | t5 | t6 |
|-| ----- | ------ | ----- | ------ | ----- | ------ |
|c1| 获得锁A  | 获得锁B | 获得锁C，C崩溃，没持久化，c1得到反馈已获取到大多数锁门，加锁成功  | C崩溃恢复 |||
|c2| 获得锁D  | 获得锁E | ...  | ... | 获取C锁成功  ||

解决方案是延迟C的崩溃恢复时间大于锁的过期时间即可，或者使用redison的watchdog机制，每10s发送一个延长时间指令，lock线程和watchdog线程同在，崩溃即watchdog线程也会崩溃
https://www.cnblogs.com/zhili/p/redLock_DistributedLock.html

32. 
springboot bean替代方案，可以继承CommandLineRunner，在run里面去替代，也可以在BeanPostProcessor里面替代

```
    @Override
    public Object postProcessBeforeInitialization(Object bean, String beanName) throws BeansException {
        if (StringUtils.endsWithIgnoreCase(beanName, targetBeanName)) {
            boolean containsBean = defaultListableBeanFactory.containsBean(targetBeanName);
            if (containsBean) {
                //移除bean的定义和实例
                defaultListableBeanFactory.removeBeanDefinition(targetBeanName);
            }
            //注册新的bean定义和实例
            defaultListableBeanFactory.registerBeanDefinition(targetBeanName, BeanDefinitionBuilder.genericBeanDefinition(Test55.class).getBeanDefinition());
            bean = null;
            return new Test55();
        }
        return bean;
    }
```
33. 
union和union all，前者会去重，后者不会去重

34. 
一致性哈希为了提高集群的容错性（减节点）和扩展性（加节点），避免需要重新计算所有值的hash，而只需要计算增减那部分节点影响的数据的hash，选择上是选最顺时针最邻近的节点存放，应对节点分布不均匀的问题，可以采用引入虚拟虚拟节点，例如三台服务器，给每台物理服务器后面加编号实现虚拟节点，RedisService1#1，RedisService1#2，RedisService1#3，RedisService2#1，RedisService2#2，RedisService2#3，RedisService3#1，RedisService3#2，RedisService3#3，
哈希槽的hash计算公式CRC-16(key)%16384，redis主从复制模式可以用来做读写分离，手动故障恢复，sentinel可以用来做自动故障恢复，但两者都可能发生数据丢失，并每台机器存储性能受单机限制，所以redis集群引入了哈希槽解决扩容和单机性能限制问题，最少配置为6台，3主3从，因为判断在线离线也要过大多数投票

参考连接：https://juejin.im/post/6844903750860013576

