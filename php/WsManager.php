<?php
/**
 * A Ratcher-powered WebSockets server.
 *
 * @author Jeff Kinnison <jkinniso@nd.edu>
 * @package pga_ws
 * @subpackage WsManager
 */
require_once __DIR__."/WsHandler.php";

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class WsManager extends Thread {
  /* Instance Variables */

  /**
   * Unit handling client connections and communications.
   *
   * @access private
   * @var WsHandler $handler
   */
  private $handler;

  /**
   * Port to listen on for connections and messages.
   *
   * @access private
   * @var int $port
   */
  protected $port;

  /**
   * The pubilc-facing WebSockets server.
   *
   * @access private
   * @var Ratchet\Server\IoServer $server
   */
  private $server;



  /* Methods */

  /**
   * Create a new WsManager instance.
   *
   * @param WsHandler $handler Class that handles incoming and outgoing communications
   * @param int $port The port the WebSockets server will listen on
   */
  public function __construct(WsHandler $handler, $port) {
    $this->handler = $handler;
    $this->port = $port;
  }

  /**
   * Start the WebSockets server.
   */
  public function run() {
    require __DIR__."/vendor/autoload.php";

    echo "Starting websockets server on ", $this->port, "\n";

    $this->server = IoServer::factory(
      new HttpServer(
        new WsServer(
          $this->handler
        )
      ),
      $this->port
    );
    $this->server->run();
  }

  /**
   * Stop the WebSockets server.
   */
  public function shutdown() {
    if ($this->server) {
      $this->handler->shutdown();
      $this->server->loop->stop();
    }
  }
}
?>
