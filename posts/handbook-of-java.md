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