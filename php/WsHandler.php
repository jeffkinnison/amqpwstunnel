<?php
/**
 * A Ratchet-powered WebSockets interface.
 *
 * @author Jeff Kinnison <jkinniso@nd.edu>
 * @package pga_ws
 * @subpackage WsHandler
 */
require __DIR__.'/vendor/autoload.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WsHandler extends Threaded implements MessageComponentInterface {
  /**
   * List of client connections.
   *
   * @access protected
   * @var SplObjectStorage $clients
   */
  protected $clients;

  /**
   * Create a new WsHandler instance.
   *
   * Set up the object map used to store active client connections.
   */
  public function __construct() {
    // Store client connections in an object map
    $this->clients = new SplObjectStorage();
  }

  /**
   * Called when a new connection is opened.
   *
   * Store the connection in the object map.
   *
   * @param ConnectionInterface $conn The object representing the new connection
   */
  public function onOpen(ConnectionInterface $conn) {
    $this->clients->attach($conn); // Store the connection
    echo "New connection ({$conn->resourceId})\n";
  }

  public function onMessage(ConnectionInterface $from, $msg) {
    $this->sendMessage($msg);
  }

  /**
   * Called when an existing connection is closed.
   *
   * Remove the closed connection from the object map.
   *
   * @param ConnectionInterface $conn The connection to remove
   */
  public function onClose(ConnectionInterface $conn) {
    // Remove the client from the connection list
    $this->clients->detach($conn);
    echo "Connection {$conn->resourceId} has disconnected\n";
  }

  /**
   * Called when an error occurs communicating with a connection.
   *
   * Close the connection and log the error.
   *
   * @param ConnectionInterface $conn The connection that encountered an error
   * @param Exception $e The error that was encountered
   */
  public function onError(ConnectionInterface $conn, \Exception $e) {
    echo "[ERROR] {$conn->resourceId}: {$e->getMessage()}";
    $conn->close();
  }

  /**
   * Send a message to the correct users.
   *
   * Generate a list of valid recipient connections and send the message to each.
   *
   * This method should be overridden in subclasses to match the communication use case.
   *
   * @param string $msg A string representation of the message to send
   */
  public function sendMessage($msg) {
    echo "Sending message ", $msg, "\n";
    $recipients = $this->getRecipientList($msg);
    foreach ($recipients as $recipient) {
      $recipient->send($msg);
    }
  }

  /**
   * Extract the intended recipients of a message from the client store.
   *
   * Generate a list of valid recipient connections based on routing information.
   *
   * This method should be overridden in subclasses to match the communication use case.
   *
   * @param $routing_info Information to filter the connection list
   * @return An iterable conataining the recipient client connections
   */
  public function getRecipientList($msg) {
    return $this->clients;
  }

  /**
   * Shut down the messaging interface.
   *
   * Close all open connections.
   */
  public function shutdown() {
    foreach ($this->clients as $client) {
      $client->close();
      $this->clients->detach($client);
    }
  }
}

if (basename($argv[0]) == basename(__FILE__)) {
  $options = getopt(["p::"]);

  $port = ($options["p"] ? (int)$options["p"] : 8888);

  $handler = new WsHandler();

  $server = IoServer::factory(
    new HttpServer(
      new WebServer(
        $handler
      )
    ),
    $port
  );

  $server->run();
}
?>
