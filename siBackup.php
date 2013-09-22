#!/usr/bin/php
<?php

/**
 * Scroll Incremental Backup Script
 * 滚动增量备份脚本
 *
 * 精英王子(m@jybox.net)
 * http://jyprince.me
 * GPLv3
 *
 * 支持的储存后端：
 *
 * * BAE
 *
 * 最低要求 PHP 5.4, 请在终端运行。
 * 依赖 tar, curl.
 * 可配合 crontab 定时运行。
 *
 * 所谓滚动增量备份就是，每次只备份自上次备份以来修改过的文件，
 * 但同时会保证一个滚动周期(默认 7 天)内的备份包中包含所有要备份的文件。
 *
 * 即，该脚本每次会备份自上次备份以来修改过的文件，和超过一个滚动周期没有备份过的文件。
 *
 * 默认情况下，该脚本需要以 root 运行，会将 /home 下的每个文件夹单独打包并上传，
 * 默认的文件名形如 2013.09.14-20-00-(<nodeName>)<username>.tar.gz .
 * 其中 nodeName 是为了将上传到同一个储存后端的，来自不同服务器的文件区分开。
 *
 * 该脚本默认会创建一个名为 /root/siBackup/ 的文件夹($dataHome)用于储存数据和临时文件。
 *
 * * $dataHome/lastBackupTime 以时间戳的形式保存上次备份时间
 * * $dataHome/BAEToken BAE 的 PCS API 授权信息
 * * $dataHome/<username>.list 本次备份的文件列表，将以 -T 参数传给 tar
 * * $dataHome/<time>-(<node>)<username>.tar.gz 每个用户的备份包，上传后会被删除
 * * $dataHome/<username>.time.json 每个文件的上次备份时间，JSON 格式
 *
 * 所有时间为 Unix 时间戳，所有路径末尾不含斜杠。
 */

if(!isset($argc))
    die("please run this script from shell");

/** @var string $dataHome 用于储存数据和临时文件的路径 */
$dataHome = "/root/siBackup";

if(!file_exists($dataHome))
    mkdir($dataHome);
$time = time();

/** @var array $exclude 要排除的文件(正则表达式) */
$exclude = [
    '^nginx\.access\.log$',
    '^nginx\.error\.log$',
    '^apache2\.access\.log$',
    '^apache2\.error\.log$',
    'wordpress-[_-\d\.\w]+\.zip$',
    '\.mp3$',
    '\.flv$',
];

/** @var int $excludeSize 备份文件的容量限制(Byte), 超过该容量的文件将不会被备份 */
$excludeSize = 10 * 1024 * 1024;

/** @var int $scrollCycle 滚动周期(s) */
$scrollCycle = 7 * 24 * 3600;

$nodeName = "main";

$uploader = BAEUploader("siBackup", 'vi4BQrheygB0SO4SFACNpGYn', 'GtWUnndR1RVXKwKY5I2iT2dXteRQG14');

date_default_timezone_set("Asia/Shanghai");
$timeStr = date("Y.m.d-H-i");

/* 配置信息结束，以下为主程序 */

$lastBackupTime = file_get_contents("{$dataHome}/lastBackupTime");
$lastBackupTime = (intval($lastBackupTime) > 0) ? intval($lastBackupTime): 0;

foreach(new DirectoryIterator("/home") as $fileinfo)
{
    if($fileinfo->isDir() && !$fileinfo->isDot() && $fileinfo->getFilename() != "lost+found")
        backupDir($fileinfo->getPathname());
}

file_put_contents("{$dataHome}/lastBackupTime", $time);

/* 主程序结束，以下为函数定义 */

function backupDir($homeDir)
{
    global $dataHome, $timeStr, $nodeName, $uploader;

    print "Searching files in {$homeDir} ...\n";

    $timefilePath = "{$dataHome}/" . basename($homeDir) . ".time.json";
    if(file_exists($timefilePath))
        $timefile = json_decode(file_get_contents($timefilePath), true);
    else
        $timefile = [];

    $listfilePath = "{$dataHome}/" . basename($homeDir) . ".list";
    file_put_contents($listfilePath, implode("\n", searchFiles($homeDir, $timefile)));
    file_put_contents($timefilePath, json_encode($timefile));

    print "Packaging files in {$homeDir} ...\n";

    $tarfilePath = "{$dataHome}/{$timeStr}-({$nodeName})" . basename($homeDir) . ".tar.gz";
    shell_exec("tar cz -C {$homeDir} -T {$listfilePath} -f '{$tarfilePath}'");

    print "BAE Uploading file " . basename($tarfilePath) . " ...\n";

    $uploader($tarfilePath);
    unlink($tarfilePath);
}

function searchFiles($homeDir, &$filetime)
{
    $files = [];

    $funcProcDir = function($dir, $homeDir) use($homeDir, &$files, &$filetime, &$funcProcDir) {
        global $exclude, $excludeSize, $scrollCycle, $lastBackupTime;

        foreach(new DirectoryIterator($dir) as $fileinfo)
        {
            /** @var DirectoryIterator $fileinfo */
            if($fileinfo->isDir() && !$fileinfo->isDot())
            {
                $funcProcDir($fileinfo->getPathname(), $files, $homeDir);
            }
            else if(!$fileinfo->isLink() && !$fileinfo->isDot())
            {
                $rFilePath = substr($fileinfo->getPathname(), strlen($homeDir) + 1);
                $rFilePathUtf8 = mb_convert_encoding($rFilePath, "UTF-8");

                foreach($exclude as $rx)
                    if(preg_match("/{$rx}/", $rFilePath))
                        continue 2;

                if($fileinfo->getSize() > $excludeSize)
                    continue;

                $mTime = $fileinfo->getMTime();

                if(
                    $mTime > $lastBackupTime or
                    !isset($filetime[$rFilePathUtf8]) or
                    intval($filetime[$rFilePathUtf8]) < (time() - $scrollCycle)
                )
                {
                    $files[] = $rFilePath;
                    $filetime[$rFilePathUtf8] = time();
                }
            }
        }
    };

    $funcProcDir($homeDir, $files, $homeDir);

    return $files;
};

function BAEUploader($BAE_AppName, $BAE_ApiKey, $BAE_SecretKey)
{
    global $dataHome, $time;

    $funcCheckToken = function() use($dataHome, $time) {
        $tokens = json_decode(file_get_contents("{$dataHome}/BAEToken"), true);

        if($tokens["createTime"] < ($time - 30 * 24 * 3600))
            return false;

        return true;
    };

    if(!file_exists("{$dataHome}/BAEToken"))
    {
        $result = json_decode(shell_exec("curl -k -L -d 'client_id={$BAE_ApiKey}&response_type=device_code&scope=basic,netdisk' 'https://openapi.baidu.com/oauth/2.0/device/code' 2>/dev/null"), true);

        $deviceCode = $result["device_code"];
        print "Please open https://openapi.baidu.com/device in web browser and input {$result['user_code']}, then press any key to continue.\n";
        fgetc(STDIN);

        $result = json_decode(shell_exec("curl -k -L -d 'grant_type=device_token&code={$deviceCode}&client_id={$BAE_ApiKey}&client_secret={$BAE_SecretKey}' 'https://openapi.baidu.com/oauth/2.0/token' 2>/dev/null"), true);

        $tokens = [];
        $tokens["createTime"] = $time;
        $tokens["refreshToken"] = $result["refresh_token"];
        $BAE_AccessToken = $tokens["accessToken"] = $result["access_token"];

        file_put_contents("{$dataHome}/BAEToken", json_encode($tokens));
    }
    else if(!$funcCheckToken)
    {
        $tokens = json_decode(file_get_contents("{$dataHome}/BAEToken"), true);
        $result = json_decode(shell_exec("curl -k -L -d 'grant_type=refresh_token&refresh_token={$tokens['refreshToken']}&client_id={$BAE_ApiKey}&client_secret={$BAE_SecretKey}' 'https://openapi.baidu.com/oauth/2.0/token' 2>/dev/null"), true);

        $tokens = [];
        $tokens["createTime"] = $time;
        $tokens["refreshToken"] = $result["refresh_token"];
        $BAE_AccessToken = $tokens["accessToken"] = $result["access_token"];

        file_put_contents("{$dataHome}/BAEToken", json_encode($tokens));
    }
    else
    {
        $tokens = json_decode(file_get_contents("{$dataHome}/BAEToken"), true);
        $BAE_AccessToken = $tokens["accessToken"];
    }

    return function($file) use($BAE_AppName, $BAE_AccessToken) {
        $filename = basename($file);
        return shell_exec("curl -k -L -F 'file=@{$file}' 'https://c.pcs.baidu.com/rest/2.0/pcs/file?method=upload&access_token={$BAE_AccessToken}&path=/apps/{$BAE_AppName}/{$filename}'");
    };
}
