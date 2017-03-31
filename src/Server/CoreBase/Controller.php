<?php
/**
 * Controller 控制器
 * 对象池模式，实例会被反复使用，成员变量缓存数据记得在销毁时清理
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Server\CoreBase;

use PG\MSF\Server\{
    SwooleMarco, SwooleServer, DataBase\RedisAsynPool, DataBase\MysqlAsynPool
};

class Controller extends CoreBase
{
    /**
     * @var RedisAsynPool
     */
    public $redisPool;
    /**
     * @var MysqlAsynPool
     */
    public $mysqlPool;
    /**
     * @var HttpInPut
     */
    public $input;
    /**
     * @var HttpOutPut
     */
    public $output;
    /**
     * 是否来自http的请求不是就是来自tcp
     * @var string
     */
    public $requestType;
    /**
     * @var \PG\MSF\Server\Client\Http\Client
     */
    public $client;
    /**
     * @var \PG\MSF\Server\Client\Tcp\Client
     */
    public $tcpClient;
    /**
     * fd
     * @var int
     */
    protected $fd;
    /**
     * uid
     * @var int
     */
    protected $uid;
    /**
     * 用户数据
     * @var
     */
    protected $clientData;
    /**
     * http response
     * @var \swoole_http_request
     */
    protected $request;
    /**
     * http response
     * @var \swoole_http_response
     */
    protected $response;

    /**
     * 用于单元测试模拟捕获服务器发出的消息
     * @var array
     */
    protected $testUnitSendStack = [];

    /**
     * 协程上正文对象
     * @var GeneratorContext
     */
    protected $generatorContext;

    /**
     * Controller constructor.
     */
    final public function __construct()
    {
        parent::__construct();
        $this->input      = new Input();
        $this->output     = new Output($this);
        $this->redisPool  = getInstance()->redisPool;
        $this->mysqlPool  = getInstance()->mysqlPool;
        $this->client     = clone getInstance()->client;
        $this->tcpClient  = clone getInstance()->tcpClient;
    }

    /**
     * 设置客户端协议数据
     * @param $uid
     * @param $fd
     * @param $clientData
     * @param $controllerName
     * @param $methodName
     */
    public function setClientData($uid, $fd, $clientData, $controllerName, $methodName)
    {
        $this->uid = $uid;
        $this->fd = $fd;
        $this->clientData = $clientData;
        $this->input->set($clientData);
        $this->requestType = SwooleMarco::TCP_REQUEST;
        $this->initialization($controllerName, $methodName);
    }

    /**
     * 初始化每次执行方法之前都会执行initialization
     * @param string $controllerName 准备执行的controller名称
     * @param string $methodName 准备执行的method名称
     */
    public function initialization($controllerName, $methodName)
    {
    }

    /**
     * set http Request Response
     * @param $request
     * @param $response
     * @param $controllerName
     * @param $methodName
     */
    public function setRequestResponse($request, $response, $controllerName, $methodName)
    {
        $this->request = $request;
        $this->response = $response;
        $this->input->set($request);
        $this->output->set($request, $response);
        $this->requestType = SwooleMarco::HTTP_REQUEST;
        $this->initialization($controllerName, $methodName);
    }

    /**
     * 设置协程上下文对象
     *
     * @param GeneratorContext $generatorContext
     * @return $this
     */
    public function setGeneratorContext(GeneratorContext $generatorContext)
    {
        $this->generatorContext = $generatorContext;
        return $this;
    }

    /**
     * 返回协程上下文对象
     *
     * @return GeneratorContext
     */
    public function getGeneratorContext()
    {
        return $this->generatorContext;
    }

    /**
     * 异常的回调
     * @param \Throwable $e
     * @throws \Throwable
     */
    public function onExceptionHandle(\Throwable $e)
    {
        switch ($this->requestType) {
            case SwooleMarco::HTTP_REQUEST:
                $this->output->setStatusHeader(500);
                $this->output->end($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
                break;
            case SwooleMarco::TCP_REQUEST:
                $this->send($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
                break;
        }
    }

    /**
     * 向当前客户端发送消息
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    public function send($data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        $data = getInstance()->encode($this->pack->pack($data));
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'send', 'fd' => $this->fd, 'data' => $data];
        } else {
            getInstance()->send($this->fd, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 销毁
     */
    public function destroy()
    {
        parent::destroy();
        unset($this->fd);
        unset($this->uid);
        unset($this->clientData);
        unset($this->request);
        unset($this->response);
        unset($this->generatorContext);
        $this->input->reset();
        $this->output->reset();
        ControllerFactory::getInstance()->revertController($this);
    }

    /**
     * 获取单元测试捕获的数据
     * @return array
     */
    public function getTestUnitResult()
    {
        $stack = $this->testUnitSendStack;
        $this->testUnitSendStack = [];
        return $stack;
    }

    /**
     * 当控制器方法不存在的时候的默认方法
     */
    public function defaultMethod()
    {
        if ($this->requestType == SwooleMarco::HTTP_REQUEST) {
            $this->output->setHeader('HTTP/1.1', '404 Not Found');
            $template = $this->loader->view('server::error_404');
            $this->output->end($template->render());
        } else {
            throw new SwooleException('method not exist');
        }
    }

    /**
     * sendToUid
     * @param $uid
     * @param $data
     * @throws SwooleException
     */
    protected function sendToUid($uid, $data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToUid', 'uid' => $this->uid, 'data' => $data];
        } else {
            getInstance()->sendToUid($uid, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * sendToUids
     * @param $uids
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    protected function sendToUids($uids, $data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToUids', 'uids' => $uids, 'data' => $data];
        } else {
            getInstance()->sendToUids($uids, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * sendToAll
     * @param $data
     * @param $destroy
     * @throws SwooleException
     */
    protected function sendToAll($data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToAll', 'data' => $data];
        } else {
            getInstance()->sendToAll($data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 发送给群
     * @param $groupId
     * @param $data
     * @param bool $destroy
     * @throws SwooleException
     */
    protected function sendToGroup($groupId, $data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new SwooleException('controller is destroy can not send data');
        }
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'sendToGroup', 'groupId' => $groupId, 'data' => $data];
        } else {
            getInstance()->sendToGroup($groupId, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 踢用户
     * @param $uid
     */
    protected function kickUid($uid)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'kickUid', 'uid' => $uid];
        } else {
            getInstance()->kickUid($uid);
        }
    }

    /**
     * bindUid
     * @param $fd
     * @param $uid
     * @param bool $isKick
     */
    protected function bindUid($fd, $uid, $isKick = true)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'bindUid', 'fd' => $fd, 'uid' => $uid];
        } else {
            getInstance()->bindUid($fd, $uid, $isKick);
        }
    }

    /**
     * unBindUid
     * @param $uid
     */
    protected function unBindUid($uid)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'unBindUid', 'uid' => $uid];
        } else {
            getInstance()->unBindUid($uid);
        }
    }

    /**
     * 断开链接
     * @param $fd
     * @param bool $autoDestroy
     */
    protected function close($fd, $autoDestroy = true)
    {
        if (SwooleServer::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'close', 'fd' => $fd];
        } else {
            getInstance()->close($fd);
        }
        if ($autoDestroy) {
            $this->destroy();
        }
    }
}