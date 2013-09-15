## siBackup
Scroll Incremental Backup Script  
滚动增量备份脚本

目前仅支持 BAE.

精英王子(m@jybox.net)  
http://jyprince.me  
GPLv3

最低要求 PHP 5.4, 请在终端运行。  
依赖 tar, curl.  
可配合 crontab 定时运行。

所谓滚动增量备份就是，每次只备份自上次备份以来修改过的文件，  
但同时会保证一个滚动周期(默认 7 天)内的备份包中包含所有要备份的文件。

即，该脚本每次会备份自上次备份以来修改过的文件，和超过一个滚动周期没有备份过的文件。
