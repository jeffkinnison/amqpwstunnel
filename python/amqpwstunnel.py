import json
import sys
import argparse

from threading import Thread, Lock

import tornado.websocket
import tornado.ioloop


class Error(Exception):
    """Base error class for exceptions in this module"""
    pass

class ConsumerConfigError(Error):
    """Raised when an issue with consumer configuration occurs"""
    def __init__(self, message):
        self.message = message

class ConsumerKeyError(Error):
    def __init__(self, message, key):
        self.message = message
        self.key = key



class PikaAsyncConsumer(Thread):
    """
    The primary entry point for routing incoming messages to the proper handler.
    """

    def __init__(self, rabbitmq_url, exchange_name, queue_name,
                 exchange_type="direct", routing_key="#"):
        """
        Create a new instance of Streamer.

        Arguments:
        rabbitmq_url -- URL to RabbitMQ server
        exchange_name -- name of RabbitMQ exchange to join
        queue_name -- name of RabbitMQ queue to join

        Keyword Arguments:
        exchange_type -- one of 'direct', 'topic', 'fanout', 'headers'
                         (default 'direct')
        routing_keys -- the routing key that this consumer listens for
                        (default '#', receives all messages)
        """
        self._connection = None
        self._channel = None
        self._shut_down = False
        self._consumer_tag = None
        self._url = rabbitmq_url
        self._client_list = []
        self._lock = Lock()

        # The following are necessary to guarantee that both the RabbitMQ
        # server and Streamer know where to look for messages. These names will
        # be decided before dispatch and should be recorded in a config file or
        # else on a per-job basis.
        self._exchange = exchange_name
        self._exchange_type = exchange_type
        self._queue = queue_name
        self._routing_key = routing_key

    def add_client(self, client):
        """
        """
        self._lock.acquire()
        self._client_list.append(client)
        self._lock.release()

    def remove_client(self, client):
        self._lock.acquire()
        for i in range(0, len(self._client_list)):
            if self._client_list[i] is client:
                self._client_list.pop(i)
                break
        self._lock.release()

    def connect(self):
        """
        Create an asynchronous connection to the RabbitMQ server at URL.
        """
        return pika.SelectConnection(pika.URLParameters(self._url),
                                     on_open_callback=self.on_connection_open,
                                     on_close_callback=self.on_connection_close,
                                     stop_ioloop_on_close=False)

    def on_connection_open(self, unused_connection):
        """
        Actions to perform when the connection opens. This may not happen
        immediately, so defer action to this callback.

        Arguments:
        unused_connection -- the created connection (by this point already
                             available as self._connection)
        """
        self._connection.channel(on_open_callback=self.on_channel_open)

    def on_connection_close(self, connection, code, text):
        """
        Actions to perform when the connection is unexpectedly closed by the
        RabbitMQ server.

        Arguments:
        connection -- the connection that was closed (same as self._connection)
        code -- response code from the RabbitMQ server
        text -- response body from the RabbitMQ server
        """
        self._channel = None
        if self._shut_down:
            self._connection.ioloop.stop()
        else:
            self._connection.add_timeout(5, self.reconnect)

    def reconnect(self):
        """
        Attempt to reestablish a connection with the RabbitMQ server.
        """
        self._connection.ioloop.stop() # Stop the ioloop to completely close

        if not self._shut_down: # Connect and restart the ioloop
            self._connection = self.connect()
            self._connection.ioloop.start()

    def on_channel_open(self, channel):
        """
        Store the opened channel for future use and set up the exchange and
        queue to be used.

        Arguments:
        channel -- the Channel instance opened by the Channel.Open RPC
        """
        self._channel = channel
        self._channel.add_on_close_callback(self.on_channel_close)
        self.declare_exchange()


    def on_channel_close(self, channel, code, text):
        """
        Actions to perform when the channel is unexpectedly closed by the
        RabbitMQ server.

        Arguments:
        connection -- the connection that was closed (same as self._connection)
        code -- response code from the RabbitMQ server
        text -- response body from the RabbitMQ server
        """
        self._connection.close()

    def declare_exchange(self):
        """
        Set up the exchange that will route messages to this consumer. Each
        RabbitMQ exchange is uniquely identified by its name, so it does not
        matter if the exchange has already been declared.
        """
        self._channel.exchange_declare(self.declare_exchange_success,
                                        self._exchange,
                                        self._exchange_type)

    def declare_exchange_success(self, unused_connection):
        """
        Actions to perform on successful exchange declaration.
        """
        self.declare_queue()

    def declare_queue(self):
        """
        Set up the queue that will route messages to this consumer. Each
        RabbitMQ queue can be defined with routing keys to use only one
        queue for multiple jobs.
        """
        self._channel.queue_declare(self.declare_queue_success,
                                    self._queue)

    def declare_queue_success(self, method_frame):
        """
        Actions to perform on successful queue declaration.
        """
        self._channel.queue_bind(self.munch,
                                 self._queue,
                                 self._exchange,
                                 self._routing_key
                                )

    def munch(self, unused):
        """
        Begin consuming messages from the Airavata API server.
        """
        self._channel.add_on_cancel_callback(self.cancel_channel)
        self._consumer_tag = self._channel.basic_consume(self._process_message)

    def cancel_channel(self, method_frame):
        if self._channel is not None:
            self._channel._close()

    def _process_message(self, ch, method, properties, body):
        """
        Receive and verify a message, then pass it to the router.

        Arguments:
        ch -- the channel that routed the message
        method -- delivery information
        properties -- message properties
        body -- the message
        """
        print("Received Message: %s" % body)
        self._lock.acquire()
        for client in self._client_list:
            self._client_list.write_message(body)
        self._lock.release()
        #self._channel.basic_ack(delivery_tag=method.delivery_tag)

    def stop_consuming(self):
        """
        Stop the consumer if active.
        """
        if self._channel:
            self._channel.basic_cancel(self.close_channel, self._consumer_tag)

    def close_channel(self):
        """
        Close the channel to shut down the consumer and connection.
        """
        self._channel.close()

    def run(self):
        """
        Start a connection with the RabbitMQ server.
        """
        self._connection = self.connect()
        self._connection.ioloop.start()

    def stop(self):
        """
        Stop an active connection with the RabbitMQ server.
        """
        self._closing = True
        self.stop_consuming()


class AMQPWSHandler(tornado.websocket.WebSocketHandler):
    """

    """
    def open(self, resource_type, resource_id):
        try:
            self.resource_id = resource_id
            self.application.add_client_to_consumer(resource_id, self)
        except AttributeError:
            print("Error: tornado.web.Application object is not AMQPWSTunnel")

    def on_message(self, message):
        pass

    def on_close(self):
        try:
            self.application.remove_client_from_consumer(self.resource_id, self)
        except KeyError:
            print("Error: resource %s does not exist" % self.resource_id)
        finally:
            self.close()


class AMQPWSTunnel(tornado.web.Application):
    """

    """

    def __init__(self, consumer_list=None, consumer_config=None handlers=None,
                 default_host='', transforms=None, **settings):
        super(AMQPWSTunnel, self).__init__(handlers=handlers,
                                           default_host=default_host,
                                           transforms=transforms,
                                           **settings)

        self.consumer_list = {} if consumer_list is None else consumer_list
        if consumer_config is None:
            raise ConsumerConfigError("No consumer configuration provided")
        self.consumer_config = consumer_config

    def consumer_exists(self, resource_id):
        """

        """
        return True if resource_id in self.consumer_list else False

    def add_client_to_consumer(self, resource_id, client):
        """

        """
        try:
            consumer = self.consumer_list[resource_id]

        except ConsumerKeyError:
            consumer = PikaAsyncConsumer(self.consumer_config.url,
                                         self.consumer_config.exchange,
                                         self.consumer_config.queue,
                                         exchange_type=self.consumer_config.type)
            self.consumer_list[resource_id] = consumer
            consumer.start()
        finally:
            consumer.add_client(client)

    def remove_client_from_consumer(self, resource_id, client):
        """

        """
        if not resource_id in self.client_list:
            raise ConsumerKeyError("Trying to remove client from nonexistent consumer", resource_id)
        self.consumer_list[resource_id].remove_client(client)


if __name__ == "__main__":
    config = json.load(sys.argv[1])

    application = AMQPWSTunnel(consumer_config=config, [
                               (r"/(experiment)/(.+)", AMQPWSHandler)
                            ])

    application.listen(8888)
    tornado.ioloop.IOLoop.current().start()
