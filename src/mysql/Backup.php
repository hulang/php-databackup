<?php

declare(strict_types=1);

namespace php\databackup\mysql;

use PDO;
use Exception;
use php\databackup\IBackup;

class Backup implements IBackup
{
    /**
     * 分卷大小的 默认2M
     * @var mixed|int
     */
    private $_volsize = 2;
    /**
     * 备份路径
     * @var mixed|string
     */
    private $_backdir = '';
    /**
     * 表集合
     * @var mixed|array 
     */
    private $_tablelist = [];
    /**
     * 当前备份表的索引 
     */
    private $_nowtableidx = 0;
    /**
     * 当前表已备份条数
     * @var mixed|int 
     */
    private $_nowtableexeccount = 0;
    /**
     * 当前表的总记录数
     * @var mixed|int 
     */
    private $_nowtabletotal = 0;
    /**
     * 当前表备份百分比
     */
    private $_nowtablepercentage = 0;
    /**
     * PDO对象
     * @var mixed|PDO 
     */
    private $_pdo;
    /**
     * 保存的文件名
     * @var mixed|string 
     */
    private $_filename = '';
    /**
     * insert Values 总条数
     * @var mixed|int|type 
     */
    private $_totallimit = 200;
    /**
     * 是否仅备份结构不备份数据
     */
    private $_onlystructure = false;
    /**
     * 
     * @param string $server 服务器
     * @param string $dbname 数据库
     * @param string $username 账户
     * @param string $password 密码
     * @param string $code 编码
     */
    /**
     * 仅备份数据结构 不备份数据的表
     */
    private $_structuretable = [];

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
     * 设置卷的大小
     * 
     * 本函数用于设置卷的大小,通过调用此方法可以更改卷的容量
     * 卷的大小对于管理存储资源非常重要,因为它决定了卷可以容纳的数据量
     * 
     * @param int $size 卷的新大小,以字节为单位
     * @return mixed|object 返回对象实例,支持链式调用
     */
    public function setvolsize($size)
    {
        $this->_volsize = $size;
        return $this;
    }

    /**
     * 设置表列表
     * 
     * 本函数用于设置一个表的列表,这个列表通常用于后续的数据库操作
     * 比如查询或者更新这些表的数据.通过这个函数,可以动态地指定
     * 一组表,以便在不同的场景下重用代码而不需要每次都硬编码表名
     *
     * @param array $tablelist 表列表,是一个数组,每个元素都是一个表名
     *                         如果不传入参数,默认为空数组,表示不设置任何表
     * @return mixed|$this 返回当前对象,支持链式调用
     */
    public function settablelist($tablelist = [])
    {
        $this->_tablelist = $tablelist;
        return $this;
    }

    /**
     * 获取数据库中的表列表
     * 
     * 本方法用于查询数据库中的所有表名,并将它们存储在一个数组中以供后续使用
     * 如果已经查询过并存储了表列表,则直接返回存储的列表,避免重复查询
     * 
     * @return mixed|array 返回包含所有表名的数组
     */
    public function gettablelist()
    {
        // 检查是否已经获取并存储了表列表
        if (!$this->_tablelist) {
            // 使用PDO查询数据库中的表状态信息
            $rs = $this->_pdo->query('show table status');
            // 获取所有查询结果,并以关联数组形式存储
            $res = $rs->fetchAll(PDO::FETCH_ASSOC);
            // 遍历查询结果,提取每个表的名称,并存储到_tablelist数组中
            foreach ($res as $r) {
                $this->_tablelist[] = $r['Name'];
            }
        }
        // 返回表列表数组
        return $this->_tablelist;
    }

    /**
     * 设置备份目录
     * 
     * 该方法用于设定备份文件存储的目录.如果目录已设定,则不进行重复设定
     * 否则将指定的新目录用于存储备份文件.如果指定的目录不存在,则尝试创建该目录
     * 
     * @param string $dir 指定的备份目录路径
     * @return mixed|object 返回当前实例,支持链式调用
     */
    public function setbackdir($dir)
    {
        // 检查是否已经设置了备份目录,如果已设置,则不进行重复设置
        if ($this->_backdir) {
            return $this;
        }
        // 设置备份目录
        $this->_backdir = $dir;
        // 检查指定的目录是否存在,如果不存在,则创建该目录
        if (!is_dir($dir)) {
            mkdir($dir, 0777);
        }
        // 返回当前实例,支持链式调用
        return $this;
    }

    /**
     * 获取返回目录路径的方法
     * 
     * 该方法用于返回指定的后退目录路径.这在需要返回到上一级目录或指定目录时非常有用
     * 例如,在一个深度嵌套的目录结构中,可能需要快速返回到一个已知的上级目录
     * 
     * @return mixed|string 返回后退目录的路径.这个路径是一个字符串,可以是相对路径或绝对路径
     */
    public function getbackdir()
    {
        return $this->_backdir;
    }

    /**
     * 设置文件名并检查文件是否存在
     * 如果文件不存在,则创建一个新的文件
     * 
     * @param string $filename 要设置的文件名
     * @return mixed|$this 返回对象实例,允许链式调用
     */
    public function setfilename($filename)
    {
        // 设置文件名
        $this->_filename = $filename;
        // 检查文件是否在指定的备份目录中存在.
        if (!is_file($this->_backdir . '/' . $this->_filename)) {
            // 如果文件不存在,则创建一个新的文件.
            fopen($this->_backdir . '/' . $this->_filename, "x+");
        }
    }

    /**
     * 获取当前操作的文件名
     * 如果文件名尚未设定,尝试根据当前的表索引从表列表中获取文件名,并附加序列号'0.sql'
     * 如果文件不存在于备份目录中,则创建一个新的文件
     * 
     * @return mixed|string 当前操作的文件名,如果不存在则返回空字符串
     */
    public function getfilename()
    {
        // 检查是否已经存在文件名,如果不存在则进行初始化
        if (!$this->_filename) {
            // 根据当前表索引获取表列表中的文件名,如果索引超出范围,则文件名为空字符串
            $this->_filename = isset($this->_tablelist[$this->_nowtableidx]) ? $this->_tablelist[$this->_nowtableidx] . '#0.sql' : '';
        }
        // 检查文件是否存在于备份目录中,如果不存在则创建新文件
        if (!is_file($this->_backdir . '/' . $this->_filename)) {
            fopen($this->_backdir . '/' . $this->_filename, "x+");
        }
        // 返回当前操作的文件名
        return $this->_filename;
    }

    /**
     * 设置备份任务的模式：仅备份结构或包括数据
     * 
     * 通过此方法,可以指定备份操作是否仅包括数据库表的结构,而不包括实际数据
     * 这对于需要定期更新数据库结构,但不需要或不希望备份实际数据的情况非常有用
     * 例如,当应用程序进行升级或迁移时,可能只需要一个最新的数据库结构备份
     *
     * @param bool $bool 指定备份模式.true表示仅备份结构,false表示备份结构和数据
     * @return mixed|$this 返回当前对象实例,支持链式调用
     */
    public function setonlystructure($bool)
    {
        $this->_onlystructure = $bool;
        return $this;
    }

    /**
     * 获取仅结构标志
     * 
     * 该方法用于返回实例中私有属性 `_onlystructure` 的值
     * 这个属性通常用于指示某些操作是否只应获取数据结构,而不获取实际数据
     * 
     * @return mixed|bool 返回布尔值,表示是否只获取结构
     */
    public function getonlystructure()
    {
        return $this->_onlystructure;
    }

    /**
     * 设置备份任务中只备份结构而不备份数据的表
     * 
     * 通过此方法,可以指定某些表在备份时只备份表结构,不备份表中的数据
     * 这对于大型数据库或包含敏感数据的表来说,是一种有效的备份策略,可以减少备份文件的大小并保护数据隐私
     * 
     * @param array $table 指定需要只备份结构的表名数组.如果不指定任何表,则默认为备份所有表的结构和数据
     * @return mixed|$this 返回当前备份任务实例,支持链式调用
     */
    public function setstructuretable($table = [])
    {
        $this->_structuretable = $table;
        return $this;
    }

    /**
     * 获取结构表名称
     * 
     * 该方法用于返回当前对象实例所关联的结构表名称
     * 结构表是指在系统设计中,用于存储特定类型数据的数据库表
     * 具体的表名由类的私有属性 `_structuretable` 存储
     * 
     * @return mixed|string 返回存储结构表名称的私有属性的值
     */
    public function getstructuretable()
    {
        return $this->_structuretable;
    }

    /**
     * 执行数据库备份操作
     * 
     * 该方法负责按步骤备份指定的数据库表.它首先确定当前要备份的表,然后构建该表的备份SQL,包括表结构和数据.备份过程中,会计算
     * 当前表的备份进度以及整个备份任务的总进度
     * 
     * @return mixed|array 返回包含当前备份表信息、备份进度等的数组
     */
    public function backup()
    {
        // 初始化总进度为100%
        $totalpercentage = 100;
        // 获取所有需要备份的表列表
        $tablelist = $this->gettablelist();
        // 用于存储当前正在备份的表名
        $nowtable = '';
        // 检查是否已完成当前表的备份,如果是,则准备备份下一个表
        // 上一次备份的表完成100% 将备份下一个表
        if (
            $this->_nowtablepercentage >= 100 &&
            isset($tablelist[$this->_nowtableidx + 1])
        ) {
            // 更新当前表索引
            $this->_nowtableidx += 1;
            // 重置当前表的已备份记录数和总记录数
            $this->_nowtableexeccount = $this->_nowtabletotal = 0;
            // 根据新的表索引设置备份文件名
            $this->setfilename($tablelist[$this->_nowtableidx] . '#0.sql');
        }
        // 如果当前表索引在表列表范围内,则处理当前表的备份
        // 备份表开始 默认第一个
        if (isset($tablelist[$this->_nowtableidx])) {
            //当前正在备份的表
            $nowtable = $tablelist[$this->_nowtableidx];
            // 用于存储当前表的备份SQL
            $sqlstr = '';
            // 如果当前表还未开始备份,则构建表结构的SQL
            if ($this->_nowtableexeccount == 0) {
                // 构建并追加"DROP TABLE IF EXISTS"的SQL语句
                $sqlstr .= 'DROP TABLE IF EXISTS `' . $nowtable . '`;' . PHP_EOL;
                // 查询并获取当前表的创建SQL,追加到备份SQL中
                $rs = $this->_pdo->query('SHOW CREATE TABLE `' . $nowtable . '`');
                $res = $rs->fetchAll();
                $sqlstr .= $res[0][1] . ';' . PHP_EOL;
                // 将表结构SQL写入备份文件
                file_put_contents($this->_backdir . DIRECTORY_SEPARATOR . $this->getfilename(), file_get_contents($this->_backdir . DIRECTORY_SEPARATOR . $this->getfilename()) . $sqlstr);
                // 如果只需备份表结构或当前表不需要备份数据,则不获取当前表的总记录数
                if ($this->getonlystructure() === false  && !in_array($nowtable, $this->getstructuretable())) {
                    $this->gettabletotal($nowtable); //当前备份表总条数
                }
            }
            // 如果当前表还有未备份的记录,则继续备份
            if ($this->_nowtableexeccount < $this->_nowtabletotal) {
                // 建记录SQL语句 并设置已经备份的条数
                $this->_singleinsertrecord($nowtable, $this->_nowtableexeccount);
            }
            // 根据当前表的已备份记录数和总记录数,计算当前表的备份进度
            // 计算单表百分比
            if ($this->_nowtabletotal != 0) {
                $this->_nowtablepercentage = $this->_nowtableexeccount / $this->_nowtabletotal * 100;
            } else {
                $this->_nowtablepercentage = 100;
            }
            // 根据当前表的备份进度和当前表在所有表中的位置,计算总备份进度
            if ($this->_nowtablepercentage == 100) {
                $totalpercentage = ($this->_nowtableidx + 1) / count($tablelist) * 100;
            } else {
                $totalpercentage = ($this->_nowtableidx) / count($tablelist) * 100;
            }
        }
        // 返回当前备份表的信息,包括表名、表索引、已备份记录数、总记录数、备份进度等
        return [
            'nowtable' => $nowtable, //当前正在备份的表
            'nowtableidx' => $this->_nowtableidx, //当前正在备份表的索引
            'nowtableexeccount' => $this->_nowtableexeccount, //当前表已备份条数
            'nowtabletotal' => $this->_nowtabletotal, //当前表总条数
            'totalpercentage' => (int) $totalpercentage, //总百分比
            'tablepercentage' => (int) $this->_nowtablepercentage, //当前表百分比
            'backfilename' => $this->getfilename(),
        ];
    }

    /**
     * 执行数据库备份的Ajax请求处理函数
     * 该函数用于处理通过Ajax方式发起的数据库备份请求.它首先检查备份目录是否已设置,然后根据预备份结果(如果存在)来配置备份过程的相关参数
     * 最后,它执行实际的备份操作,并返回备份结果及备份目录信息
     *
     * @param array $preresult 预备份结果数组,包含备份过程中的中间状态信息,如当前备份的表索引、执行计数、总表数及备份文件名等
     * @return mixed|array 返回包含备份结果和备份目录信息的数组
     * @throws Exception 如果备份目录未设置,则抛出异常
     */
    public function ajaxbackup($preresult = [])
    {
        // 检查备份目录是否已设置,如果没有且没有预备份结果中的备份目录信息,则抛出异常
        if ($this->getbackdir() == '' && !isset($preresult['backdir'])) {
            throw new Exception('请先设置备份目录');
        }
        // 如果有预备份结果中的备份目录信息,则更新当前的备份目录设置
        if (isset($preresult['backdir'])) {
            $this->setbackdir($preresult['backdir']);
        }
        // 从预备份结果中移除备份目录信息,以免影响后续处理
        unset($preresult['backdir']);
        // 如果预备份结果不为空,则根据其中的信息配置当前的备份进度参数和备份文件名
        if ($preresult) {
            $this->_nowtableidx = $preresult['nowtableidx'];
            $this->_nowtableexeccount = $preresult['nowtableexeccount'];
            $this->_nowtabletotal = $preresult['nowtabletotal'];
            $this->_nowtablepercentage = (int) $preresult['tablepercentage'];
            $this->setfilename($preresult['backfilename']);
        }
        // 执行实际的备份操作,并获取备份结果
        $result = $this->backup();
        // 将当前的备份目录信息添加到备份结果中
        $result['backdir'] = $this->getbackdir();
        // 返回备份结果和备份目录信息
        return $result;
    }

    /**
     * 获取指定表中的记录总数
     * 
     * 通过执行一个SQL查询来获取指定表中的记录总数.此方法适用于任何需要知道表中记录数量的场景,如分页处理、数据统计等
     * 
     * @param string $table 需要查询记录数的表名
     * @return mixed|int 返回表中的记录总数
     */
    public function gettabletotal($table)
    {
        // 使用PDO执行SQL查询,查询内容为计算指定表中的记录总数
        $value = $this->_pdo->query('select count(*) from ' . $table);
        // 获取查询结果并以数字数组形式存储
        $counts = $value->fetchAll(PDO::FETCH_NUM);
        // 返回表中的记录总数,存储在结果数组的第一个元素的第一个位置
        return $this->_nowtabletotal = $counts[0][0];
    }

    /**
     * 分批插入记录到单个表
     * 
     * 该方法用于将从数据库查询到的数据分批插入到指定的表中
     * 它通过限制每次插入的数据量来实现批量插入,以减少数据库操作的负载
     * 
     * @param string $tablename 需要插入数据的表名
     * @param int $limit 每次查询的数据量限制
     * @return void
     */
    private function _singleinsertrecord($tablename, $limit)
    {
        // 构造SQL语句,用于查询指定数量的数据
        $sql = 'select * from `' . $tablename . '` limit ' . $limit . ',' . $this->_totallimit;
        // 执行查询
        $valuers = $this->_pdo->query($sql);
        // 获取查询结果,并以数字索引的数组形式存储
        $valueres = $valuers->fetchAll(PDO::FETCH_NUM);
        // 初始化用于存储插入SQL语句的变量
        $insertsqlv = '';
        // 初始化插入SQL语句的主框架
        $insertsql = 'insert into `' . $tablename . '` VALUES ';
        // 遍历查询结果,构建插入SQL语句的详细部分
        foreach ($valueres as $v) {
            $insertsqlv .= ' ( ';
            foreach ($v as $_v) {
                // 对数据值进行SQL转义,防止SQL注入
                $value = '';
                switch ($_v) {
                    case null:
                    case '':
                        $value = (string) $_v;
                        break;
                    default:
                        $value = (string) $_v;
                        break;
                }
                $insertsqlv .=  $this->_pdo->quote($value)  . ',';
                unset($value);
            }
            // 移除最后一个逗号,为每个记录的结尾做准备
            $insertsqlv = rtrim($insertsqlv, ',');
            $insertsqlv .= ' ),';
        }
        // 移除最后一个逗号,完成所有记录的插入语句
        $insertsql .= rtrim($insertsqlv, ',') . ' ;' . PHP_EOL;
        // 检查生成的SQL文件的大小,以防止文件过大
        $this->_checkfilesize();
        // 将构建好的插入SQL语句追加到SQL文件中
        file_put_contents($this->_backdir . '/' . $this->getfilename(), file_get_contents($this->_backdir . '/' . $this->getfilename()) . $insertsql);
        // 更新当前表的执行计数,用于跟踪已处理的数据量
        $this->_nowtableexeccount += $this->_totallimit;
        // 确保执行计数不超过当前表的总数据量
        $this->_nowtableexeccount = $this->_nowtableexeccount >= $this->_nowtabletotal ? $this->_nowtabletotal : $this->_nowtableexeccount;
    }

    /**
     * 检查文件大小,确保文件不会超过预设的卷大小
     * 如果文件大小超过卷大小,将文件名修改为下一個卷的文件名
     * 这个方法是私有的,意味着它只能在类内部被调用
     * 
     * @return void 该方法没有返回值
     */
    private function _checkfilesize()
    {
        // 清除文件状态缓存,以获取最新的文件大小信息
        clearstatcache();
        // 检查当前备份文件是否超过卷大小
        // 如果文件大小小于卷大小,则$ b被设置为true,否则为false
        $b = filesize($this->_backdir . '/' . $this->getfilename()) < $this->_volsize * 1024 * 1024 ? true : false;
        // 如果文件大小超过卷大小
        if ($b === false) {
            // 将文件名按'#'分割,以获取文件扩展名和文件名主体.
            $filearr = explode('#', $this->getfilename());
            // 如果文件名包含两个部分(表示这是一个分卷文件)
            if (count($filearr) == 2) {
                // 从文件扩展名中分离出数字部分,用于计算下一个卷的编号
                $fileext = explode('.', $filearr[1]); //.sql
                // 构造下一个卷的文件名
                // 文件名主体不变,卷编号加一,保持文件扩展名
                $filename = $filearr[0] . '#' . ($fileext[0] + 1) . '.sql';
                // 设置新的文件名,为创建下一个卷做准备
                $this->setfilename($filename);
            }
        }
    }
}
