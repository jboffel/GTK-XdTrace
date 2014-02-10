GTK-XdTrace
===========

Make usage of tracefile almost as like a coredump for C language. You can load a trace file and navigate throw the source code of your application in a similar way of a debugger.

But, trace file will only show function call. Then unlike debugger that will show all instructions, you will only navigate throw function call.
Despite that, I believe it give a good overview of where we passed in the source code.

For example if you have source code like that :

$test = calltofunc();

if($test) {

    $res = 0;
    
} else {

    $res = 1;
    
}

calltofunc2($res);

trace file will only show you lines $test = calltofunc(); and calltofunc2($res);

But as you know what is parameters value and return value of functions, you can mostly guessed where you passed.

I recommend that configuration for Xdebug trace parameters :

xdebug.trace_enable_trigger = 1

xdebug.trace_format = 0

xdebug.trace_output_dir = "C:\Users\JEANBOFF\xampp\tmp"

xdebug.trace_output_name = "xdebug_trace.%p.%s.%u"

xdebug.collect_params = 4

xdebug.show_mem_delta = 1

xdebug.collect_return = 1

But becarefull, when collecting parameters, execution time will be much more longer and size of the file could be several 100Mo.
Then I setted in the windows release a memory limit of 2Gb.

Also in php.ini of your application, when using trace, you should set a higher max execution time than 30s to not miss end of the trace fiel.