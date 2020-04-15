# Software Analysis lecture3 笔记-Data Flow Analysis I

这一节和下一节讲数据流分析的应用部分。静态分析技术里面的应用有很多，但这门课会详细讲到的只有三种，分别为：

+ Reaching Definitons Analysis
+ Live Variables Analysis
+ Available Expression Analysis

选用这三种的原因是这三种在静态分析技术里面非常有代表性，其中RDA和LVA为may analysis，AEA为must analysis；而may analysis的意思就是上节课讲到的对程序进行over-approximate，而must analysis即是对程序进行under-approximate，或者可以更为这样理解，may analysis输出的报告是一种特称命题（存在分支使得X发生），而must analysis是一种全称命题（对于所有分支，X必然发生）；让我们来看例子

```
int x = 1;
int y;
if(input < 10) {
    x = 11 
} else {
    x = 29
    y = 100
}
return (input.equals(x) ? 1 : 2) + y
```
对以上代码做未初始化变量检测分析的时候，输出报告为*y可能未初始化*，这就是一种may analysis

在有些语言中会将较小的数字缓存在常量区以便高效复用，假设这是一门会对-128到127之间的常量缓存，而这些被缓存的数字之间的equals可以等价替换为==（不在缓存区的整数必须用equals比较）；那么可以对以上代码做小整数比较符优化的时候，输出报告为*x在-128到127之间总成立*，得到这个报告可以指导编译器将equals替换成==，这个就是must analysis。

待续...
