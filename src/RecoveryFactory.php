<?php

declare(strict_types=1);

namespace php\databackup;

use php\databackup\mysql\Recovery;
use PDO;

class RecoveryFactory
{
    private static $instance = null;

    /**
     * 获取数据库操作实例
     * 
     * 本函数用于根据传入的数据库连接参数,返回一个对应的数据库操作实例.如果相同参数的实例已存在
     * 则直接返回该实例,实现单例模式,避免重复建立数据库连接
     * 
     * @param string $scheme 数据库连接协议,目前只支持'mysql'
     * @param string $server 数据库服务器地址
     * @param string $dbname 数据库名称
     * @param string $username 登录数据库的用户名
     * @param string $password 登录数据库的密码
     * @param string $code 数据库字符集,默认为'utf8'
     * @return Recovery 数据库操作实例
     */
    public static function instance($scheme, $server, $dbname, $username, $password, $code = 'utf8')
    {
        // 使用参数组合的字符串作为唯一标识,计算其MD5值
        $args = md5(implode('_', func_get_args()));

        // 检查是否已存在相同参数的实例,若不存在或为null,则创建新实例
        if (!isset(self::$instance[$args]) || self::$instance[$args] == null) {
            switch ($scheme) {
                case 'mysql':
                    // 使用PDO建立数据库连接,并设置字符集
                    $pdo =  new PDO($scheme . ':host=' . $server . ';dbname=' . $dbname, $username, $password, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES'" . $code . "';"]);
                    // 创建Recovery实例,并将其存入静态变量$instance中
                    self::$instance[$args] = new Recovery($pdo);
                    break;
            }
        }
        // 返回对应参数的数据库操作实例
        return self::$instance[$args];
    }
}
