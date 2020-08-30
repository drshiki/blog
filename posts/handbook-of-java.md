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
