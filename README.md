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

