<?php

declare(strict_types=1);

namespace php\databackup\mysql;

use PDO;
use Exception;
use php\databackup\IRecovery;

class Recovery implements IRecovery
{
    /**
     * PDO对象
     * @var mixed|PDO
     */
    private $_pdo;

    /**
     * SQL文件所在的目录
     * @var mixed|string
     */
    private $_sqlfiledir = '';

    /**
     * SQL文件数组
     * @var mixed|array
     */
    private $_sqlfilesarr = [];

    /**
     * 当前恢复文件数组的索引
     * @var mixed|int
     */
    private $_nowfileidx = 0;

    /**
     * 下一个恢复的文件
     * @var mixed|int
     */
    private $_nextfileidx = 0;

    /**
     * 构造函数用于初始化PDO实例
     * 
     * 本类的构造函数接受一个PDO对象作为参数,将该PDO对象存储为类的私有属性
     * 这使得类可以在后续的操作中使用提供的PDO对象来访问数据库
     * 
     * @param PDO $pdo 一个有效的PDO对象,用于数据库操作
     */
    public function __construct($pdo)
    {
        $this->_pdo = $pdo;
    }

    /**
     * 设置SQL文件目录
     * 
     * 本函数用于设置SQL文件所在的目录.通过这个目录,类可以找到并处理相关的SQL文件
     * 设置目录后,可以通过链式调用返回对象本身,方便进行进一步的操作
     * 
     * @param string $dir SQL文件所在的目录路径
     * @return object 返回对象本身,支持链式调用
     */
    public function setSqlfiledir($dir)
    {
        $this->_sqlfiledir = $dir;
        return $this;
    }

    /**
     * 获取SQL文件列表
     * 
     * 该方法用于扫描指定目录中的SQL文件,并组织成一个数组返回
     * 数组的键是文件名(不包括扩展名),值是文件扩展名和文件名
     * 文件名按照字母顺序排序,同一文件名的不同扩展名也按照字母顺序排序
     * 
     * @return mixed|array 返回包含所有SQL文件名的数组
     */
    public function getfiles()
    {
        // 检查是否已经加载过文件列表,如果未加载则进行加载
        if (!$this->_sqlfilesarr) {
            // 定义目录,使用DirectoryIterator遍历该目录
            $dir = $this->_sqlfiledir;
            $iterator = new \DirectoryIterator($dir);
            $filesarr = [];
            // 遍历目录中的每个文件
            foreach ($iterator as $it) {
                // 排除当前目录和父目录的引用
                if (!$it->isDot()) {
                    // 分割文件名以获取文件名和扩展名
                    $filenameinfo = explode('#', $it->getFilename());
                    $fileext = explode('.', $filenameinfo[1]);
                    // 按文件名和扩展名组织文件数组
                    $filesarr[$filenameinfo[0]][$fileext[0]] = $it->getFilename();
                }
            }
            // 对文件名进行排序
            ksort($filesarr);
            // 对每个文件的扩展名进行排序
            foreach ($filesarr as $k => $f) {
                ksort($f);
                $filesarr[$k] = $f;
            }
            // 将排序后的文件名添加到_sqlfilesarr属性中
            foreach ($filesarr as $f) {
                foreach ($f as $_f) {
                    $this->_sqlfilesarr[] = $_f;
                }
            }
        }
        // 返回文件列表数组
        return $this->_sqlfilesarr;
    }

    /**
     * 数据恢复方法
     * 本方法旨在通过逐个处理SQL文件来恢复数据.它首先尝试获取文件列表,然后按照索引顺序处理每个文件
     * 如果当前索引对应的文件存在,则执行该文件的导入操作,并计算出当前的恢复进度
     * 最后,返回当前处理的文件索引、下一个要处理的文件索引以及整体的恢复进度
     * 在出现异常的情况下,会重新抛出异常以供上层处理
     *
     * @return mixed|array 返回包含当前文件索引、下一个文件索引和恢复进度的数组
     * @throws Exception 如果在恢复过程中发生任何异常,将会抛出异常
     */
    public function recovery()
    {
        try {
            // 尝试获取文件列表
            $filesarr = $this->getfiles();
            // 初始化总进度为100
            $totalpercentage = 100;
            // 设置当前文件索引为下一个要处理的文件索引
            $this->_nowfileidx = $this->_nextfileidx;
            // 检查当前索引是否在文件列表范围内
            if (isset($filesarr[$this->_nowfileidx])) {
                // 如果当前索引对应的文件存在,则执行该文件的导入操作
                $this->_importsqlfile($this->_sqlfiledir . DIRECTORY_SEPARATOR . $filesarr[$this->_nowfileidx]);
                // 计算并更新当前的恢复进度
                $totalpercentage = $this->_nowfileidx / count($this->_sqlfilesarr) * 100;
                // 更新下一个要处理的文件索引
                $this->_nextfileidx = $this->_nowfileidx + 1;
            }
            // 返回当前文件索引、下一个文件索引和恢复进度
            return [
                'nowfileidex' => $this->_nowfileidx,
                'nextfileidx' => $this->_nextfileidx,
                'totalpercentage' => (int) $totalpercentage,
            ];
        } catch (Exception $ex) {
            // 如果在处理过程中发生异常,则重新抛出异常
            throw $ex;
        }
    }

    /**
     * 使用Ajax方式进行数据恢复的操作方法
     * 
     * 本方法主要用于通过Ajax请求来执行数据恢复流程.它首先检查是否传入了预恢复结果,如果有,则利用这些结果来设定当前和下一个文件的索引,以便继续中断前的恢复工作
     * 接着,它调用内部的recovery方法来实际执行恢复操作,并将结果返回
     * 
     * @param array $preresult 预恢复结果数组,包含nowfileidex和nextfileidx两个元素,用于指定恢复的起始点
     *                         如果为空,则表示从头开始恢复
     * @return mixed|array 返回数据恢复的结果,具体形式取决于recovery方法的实现
     */
    public function ajaxrecovery($preresult = [])
    {
        // 检查是否有预恢复结果传入,如果有,则更新当前和下一个文件的索引
        if ($preresult) {
            $this->_nowfileidx = $preresult['nowfileidex'];
            $this->_nextfileidx = $preresult['nextfileidx'];
        }
        // 调用recovery方法执行实际的数据恢复操作
        $result = $this->recovery();
        // 返回恢复结果
        return $result;
    }

    /**
     * 私有方法：导入SQL文件
     * 该方法用于读取并执行一个SQL文件中的所有语句.它首先检查文件是否存在,然后逐行读取并执行SQL语句
     * 如果在执行过程中遇到任何异常,方法将捕获异常并返回false,表示导入失败
     * 
     * @param string $sqlfile SQL文件的路径.必须是文件存在且可读的路径
     * @return mixed|bool 如果文件成功导入则返回true,否则返回false
     */
    private function _importsqlfile($sqlfile)
    {
        // 检查SQL文件是否存在
        if (is_file($sqlfile)) {
            try {
                // 读取SQL文件内容
                $content = file_get_contents($sqlfile);
                // 将内容按分号和换行符分割成数组,以逐条处理SQL语句
                $arr = explode(';' . PHP_EOL, $content);
                foreach ($arr as $a) {
                    // 忽略空行或仅包含空白字符的行
                    if (trim($a) != '') {
                        // 执行SQL语句
                        $this->_pdo->exec($a);
                    }
                }
            } catch (Exception $ex) {
                // 如果在执行过程中发生异常,捕获异常并返回false
                return false;
            }
        }
        // 如果文件存在且执行成功,或文件不存在,则返回true
        return true;
    }
}
