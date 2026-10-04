<?php namespace TooBasic;

#[\AllowDynamicProperties]
class Pdo extends \PDO
{
	/**
	 * PDO 构造函数
	 * 
	 * 初始化数据库连接并设置错误模式为异常模式。
	 * 这样在查询失败时会抛出异常而不是返回错误。
	 * 
	 * @param string $dsn 数据源名称（DSN）
	 * @param string|null $username 数据库用户名
	 * @param string|null $password 数据库密码
	 * @param array $options PDO 选项数组
	 */
	public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = array())
	{
		parent::__construct($dsn, $username, $password, $options);

		$this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	}

	/**
	 * 执行查询并返回单个结果对象
	 * 
	 * 执行 SQL 查询并期望只返回一行结果。
	 * 如果返回的行数不是恰好一行，则抛出异常。
	 * 
	 * @param string $query SQL 查询语句
	 * @param array $params 查询参数数组
	 * @param string $class 要将结果映射到的类名，默认为 StdClass
	 * @return object 查询结果对象
	 * @throws Exception 当查询结果不是恰好一行时抛出异常
	 */
	public function fetchObject(string $query, array $params = [], string $class = 'StdClass')
	{
		$r = $this->fetchObjects($query, $params, $class);

		if (1 != count($r))
			throw new Exception('Query returned '. count($r) .' rows, no single result');

		return $r[0];
	}

	/**
	 * 执行查询并返回多个结果对象
	 * 
	 * 使用预处理语句执行 SQL 查询，返回所有匹配的行。
	 * 结果被映射到指定的类。
	 * 
	 * @param string $query SQL 查询语句
	 * @param array $params 查询参数数组
	 * @param string $class 要将结果映射到的类名，默认为 StdClass
	 * @return array 对象数组，每个对象代表一行结果
	 */
	public function fetchObjects(string $query, array $params, string $class = 'StdClass')
	{
		$s = $this->preparedQuery($query, $params);
		return $s->fetchAll(PDO::FETCH_CLASS, $class);
	}

	/**
	 * 执行预处理语句并返回受影响的行数
	 * 
	 * 准备并执行一条 SQL 语句（通常用于 INSERT、UPDATE、DELETE）。
	 * 返回被修改的行数。
	 * 
	 * @param string $query SQL 查询语句
	 * @param array $params 查询参数数组
	 * @return int 受影响的行数
	 */
	public function preparedExec(string $query, array $params)
	{
		$s = $this->prepare($query);
		$s->execute($params);

		return $s->rowCount();
	}

	/**
	 * 准备并执行预处理查询语句
	 * 
	 * 使用提供的参数准备并执行 SQL 查询。
	 * 返回 PDOStatement 对象，可以用于获取结果。
	 * 
	 * @param string $query SQL 查询语句
	 * @param array $params 查询参数数组
	 * @return \PDOStatement 执行后的语句对象
	 */
	public function preparedQuery(string $query, array $params)
	{
		$s = $this->prepare($query);
		$s->execute($params);

		return $s;
	}
}
