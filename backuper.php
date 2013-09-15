#!/usr/bin/php
<?php

/**
 * Scroll Incremental Backup Script
 * 滚动增量备份脚本
 *
 * 目前仅支持 BAE.
 *
 * 精英王子(m@jybox.net)
 * http://jyprince.me
 * GPLv3
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
 * 其中 nodeName 是为了将上传到同一个网盘的，来自不同服务器的文件区分开。
 *
 * 该脚本默认会创建一个名为 /root/siBackup/ 的文件夹($dataHome)用于储存数据和临时文件。
 *
 * * $dataHome/lastBackupTime 以时间戳的形式保存上次备份时间
 * * $dataHome/<username>.list 本次备份的文件列表，将以 -T 参数传给 tar
 * * $dataHome/<time>-(<node>)<username>.tar.gz 每个用户的备份包，上传后会被删除
 * * $dataHome/<username>.time.json 每个文件的上次备份时间，JSON 格式
 *
 * 所有时间为 Unix 时间戳，所有路径末尾不含斜杠。
 */

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

/** @var string $dataHome 用于储存数据和临时文件的路径 */
$dataHome = "/root/siBackup";

$nodeName = "main";

$uploader = BAEUploader("siBackup", '3.a9a6b186d8deab1ad52fa2313d605cdf.2052090.1618377470.3719205256-1124134');

$timeStr = date("Y.m.d-H-i");

//error_reporting(0);

/* 配置信息结束，以下为主程序 */

if(!isset($argc))
    die("please run this script from shell");

if(!file_exists($dataHome))
    mkdir($dataHome);
$time = time();

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

    print "Packaging file in {$homeDir} ...\n";

    $tarfilePath = "{$dataHome}/{$timeStr}-({$nodeName})" . basename($homeDir) . ".tar.gz";
    shell_exec("tar cz -C {$homeDir} -T {$listfilePath} -f '{$tarfilePath}'");

    print "BAE Uploading file " . basename($tarfilePath) . " ...\n";

    print $uploader($tarfilePath) . "\n";
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

                foreach($exclude as $rx)
                    if(preg_match("/{$rx}/", $rFilePath))
                        continue 2;

                if($fileinfo->getSize() > $excludeSize)
                    continue;

                $mTime = $fileinfo->getMTime();

                if(
                    $mTime > $lastBackupTime or
                    !isset($filetime[$rFilePath]) or
                    intval($filetime[$rFilePath]) < (time() - $scrollCycle)
                )
                {
                    $files[] = $rFilePath;
                    $filetime[$rFilePath] = time();
                }
            }
        }
    };

    $funcProcDir($homeDir, $files, $homeDir);

    return $files;
};

function BAEUploader($BAE_AppName, $BAE_AccessToken)
{
    /**
     * Access Token 获取流程
     * 改写自 http://www.haiyun.me/archives/859.html
     *
     * 登录 http://developer.baidu.com/dev#/create , 注册成为百度开发者，
     * 创建一个应用，并开通 PCS API 的权限。
     *
     * 通过 API Key 获取 Device Code 和 User Code:
     *
     *     curl -k -L -d 'client_id=<API Key>&response_type=device_code&scope=basic,netdisk' 'https://openapi.baidu.com/oauth/2.0/device/code'
     *
     * 在浏览器打开 https://openapi.baidu.com/device , 输入获取到的 User Code, 提交。
     *
     * 通过 Device Code 获取 Refresh Token 和 Access Token;
     *
     *     curl -k -L -d 'grant_type=device_token&code=<Device Code>&client_id=<API Key>&client_secret=<API Secret>' 'https://openapi.baidu.com/oauth/2.0/token'
     *
     * 此时已经获取到 Access Token, Access Token 有效期为 30 天，30 天后需要刷新 Access Token:
     *
     *     curl -k -L -d 'grant_type=refresh_token&refresh_token=<Refresh Token>&client_id=<API Key>&client_secret=<API Secret>' 'https://openapi.baidu.com/oauth/2.0/token'
     *
     */
    return function($file) use($BAE_AppName, $BAE_AccessToken) {
        $filename = basename($file);
        return shell_exec("curl -k -L -F 'file=@{$file}' 'https://c.pcs.baidu.com/rest/2.0/pcs/file?method=upload&access_token={$BAE_AccessToken}&path=/apps/{$BAE_AppName}/{$filename}'");
    };
}