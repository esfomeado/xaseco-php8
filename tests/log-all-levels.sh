# test copy only: log every PHP error level XAseco's handler would ignore
perl -0pi -e 's/(\t\t\/\/\$message = "\[PHP \$errno\] \$errstr on line \$errline in file \$errfile" \. CRLF;\r\n)/\t\techo "[PHP level \$errno] \$errstr on line \$errline in file \$errfile" . CRLF;\r\n$1/' aseco.php
grep -c 'PHP level' aseco.php
