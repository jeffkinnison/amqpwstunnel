<?php
/**
 * Interface for receiving messages from an AMQP queue.
 *
 * @author Jeff Kinnison <jkinniso@nd.edu>
 * @package pga_ws
 * @subpackage AMQPConsumer
 */
require __DIR__.'/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class AMQPConsumer {

  /* AMQP Server Information */

  /**
   *  URL for the AMQP server.
   *
   * @access protected
   * @var string $url
   */
  protected $url;

  /**
   *  Port that the AMQP server listens on.
   *
   * @access protected
   * @var int $port
   */
  protected $port;

  /**
   * Username to log into the AMQP server
   *
   * @access protected
   * @var string $user
   */
  protected $user;

/**
 *  Password to log into the AMQP server.
 *
 * @access protected
 * @var string $pass
 */
  protected $pass;



  /* Queue and Exchange Information */

  /**
   *  Name of the exchange to bind to.
   *
   * @access protected
   * @var string $exchange
   */
  protected $exchange;

  /**
   *  Name of the queue to listen to.
   *
   * @access protected
   * @var string $queue
   */
  protected $queue;

  /**
   *  Type of the exchange.
   *
   * One of "fanout", "direct", "topic", or "headers"
   *
   * @access protected
   * @var string $exchange_type
   */
  protected $exchange_type;

  /**
   *  Routing key for messages to this consumer.
   *
   * @access protected
   * @var string $routing_key
   */
  protected $routing_key;



  /* AMQP Server Connection Interfaces */

  /**
   *  Object representing the connection to the AMQP server.
   *
   * @access private
   * @var PhpAmqpLib\Connection\AMQPStreamConnction $connection
   */
  private $connection;

  /**
   *  Object representing the channel to the AMQP exchange and queue.
   *
   * @access private
   * @var PhpAmqpLib\Channel\AMQPChannel
   */
  private $channel;



  /* Internal Management */

  /**
   * Whether or not the consumer is currently running.
   *
   * Used to determine when to start and stop consuming messages. Activated
   * in $this->run() once $this->connection and $this->channel are set up.
   * Set to false after starting the consumer to shut it down.
   *
   * @access private
   * @var bool $running
   */
  private $running;



  /* WebSockets Integration */

  /**
   * Port that the websockets server should listen on.
   *
   * @access private
   * @var int $ws_port
   */

  /**
   * Handle to a WebSockets message sending handler.
   *
   * @access private
   * @var WsHandler $handler
   */
  private $handler;

  /**
   * Handle to a running WebSockets server for message passing.
   *
   * @access private
   * @var WsManager $ws
   */
  private $ws;


  /* Methods */

  /**
   * Create a new instance of AMQPConsumer.
   *
   * Constructs the Thread parent class and stores information to connect to and consume messages from an AMQP queue.
   *
   * @param string $url
   * @param int $port
   * @param string $user
   * @param string $pass
   * @param string $exchange
   * @param string $queue
   * @param string $exchange_type
   * @param string $routing_key
   * @param int $ws_port
   */
  public function __construct($url="localhost", $port=5672, $user="guest", $pass="guest",
                              $exchange="ws_relay", $queue="relay", $exchange_type="direct",
                              $routing_key="to_ws", $ws_port=8888) {
    $this->url = $url;
    $this->port = $port;
    $this->user = $user;
    $this->pass = $pass;
    $this->exchange = $exchange;
    $this->queue = $queue;
    $this->exchange_type = $exchange_type;
    $this->routing_key = $routing_key;
    $this->ws_port = $ws_port;
    $this->callback = $callback;
    $this->running = false;
  }

  /**
   * Shut down the consumer.
   *
   * Close any active connection or channel and clear the references.
   */
  public function __destruct() {
    if ($this->channel) {
      $this->channel->close();
      $this->channel = null;
    }

    if ($this->connection) {
      $this->connection->close();
      $this->connection = null;
    }
  }

  /**
   * Begin consuming messages from the queue.
   *
   * Specified that any received message be run through $this->callback.
   */
  public function consume() {
    echo "Consuming...\n";
    $this->channel->basic_consume($this->queue, '', false, true, false, false, array($this, "passToWS"));
    $this->running = true;
    while ($this->running && count($this->channel->callbacks)) {
      $this->channel->wait();
    }
  }

  /**
   * Start the consumer.
   *
   * In a new thread, set up the connection, channel, exchange, and queue, then begin consuming.
   */
  public function run() {
    $this->setupWsServer();
    $this->openConnection();
    $this->setupQueue();
    $this->consume();
  }

  /**
   * Connect to the AMQP server.
   *
   * Open a new connection to the AMQP server and create a channel.
   */
  public function openConnection() {
    echo "Opening connection to ", $this->url, "\n";
    $this->connection = new AMQPStreamConnection($this->url, $this->port, $this->user, $this->pass, "messaging", false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 1);
    echo "Opening channel\n";
    //while (!$this->connection->isConnected()) {}
    $this->channel = $this->connection->channel();
  }

  public function passToWS($msg) {
    echo $msg->body, "\n";
    $this->handler->synchronized(function($handler, $m) {
      $handler->sendMessage($m->body);
    }, $this->handler, $msg);
    #$this->handler->sendMessage($msg->body);
  }

  /**
   * Set up the queue to consume.
   *
   * Declare the exchange and queue that the consumer should listen to, and bind to the routing key.
   */
  public function setupQueue() {
    echo "Declaring exchange ", $this->exchange, "\n";
    $this->channel->exchange_declare($this->exchange, $this->exchange_type, false, false, false);
    echo "Declaring queue ", $this->queue, "\n";
    list($queue_name) = $this->channel->queue_declare($this->queue, false, true, false, false);
    echo "Binding to queue ", $queue_name, "\n";
    $this->channel->queue_bind($queue_name, $this->exchange, $this->routing_key);
  }

  public function setupWsServer() {
    $this->handler = new WsHandler();
    $this->ws = new WsManager($this->handler, $this->ws_port);
    $this->ws->start();
  }

  /**
   * Signal the consumer to stop consuming.
   */
  public function shutdown() {
    $this->running = false;
  }
}

if (basename($argv[0]) == basename(__FILE__)) {
  require __DIR__."/WsManager.php";

  $long_opts = array(
    "amqp-host", "amqp-port", "amqp-user", "amqp-pass", "exchange", "queue",
    "exchange-type", "routing-key", "ws-port"
  );
  $options = getopt("", $long_opts);

  $host = (array_key_exists("amqp-host", $options) ? $options["amqp-host"] : "localhost");
  $port = (array_key_exists("amqp-port", $options)  ? (int)$options["amqp-port"] : 5672);
  $user = (array_key_exists("amqp-user", $options)  ? $options["amqp-user"] : "guest");
  $pass = (array_key_exists("amqp-pass", $options)  ? $options["amqp-pass"] : "guest");
  $exch = (array_key_exists("exchange", $options)  ? $options["exchange"] : "wstest");
  $queue = (array_key_exists("queue", $options)  ? $options["queue"] : "results");
  $type = (array_key_exists("exchange-type", $options)  ? $options["exchange-type"] : "topic");
  $rkey = (array_key_exists("routing-key", $options)  ? $options["routing-key"] : "experiment");
  $ws_port = (array_key_exists("ws-port", $options)  ? (int)$options["ws-port"] : 8888);

  #$websocket = new WsManager(new WsHandler(), $ws_port);
  $consumer = new AMQPConsumer("gw56.iu.xsede.org", $port, "airavata", "airavata", $exch, $queue, $type, $rkey, $ws_port);
  $consumer->run();
}
?>
