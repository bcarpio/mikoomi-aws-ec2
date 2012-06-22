#!/bin/bash 
/usr/bin/php /usr/local/etc/zabbix/bin/mikoomi-aws-ec2-overview-plugin.php -a $1 -k $2 -s $3 -e $4 -z $5 > /dev/null 2>&1
