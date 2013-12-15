<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Protocol\Text;

use Predis\CommunicationException;
use Predis\Command\CommandInterface;
use Predis\Connection\ComposableConnectionInterface;
use Predis\Protocol\ProtocolException;
use Predis\Protocol\ProtocolProcessorInterface;
use Predis\Response\Status as StatusResponse;
use Predis\Response\Error as ErrorResponse;
use Predis\Response\Iterator\MultiBulk as MultiBulkIterator;

/**
 * Protocol processor for the standard Redis wire protocol.
 *
 * @link http://redis.io/topics/protocol
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class ProtocolProcessor implements ProtocolProcessorInterface
{
    protected $mbiterable;
    protected $serializer;

    /**
     *
     */
    public function __construct()
    {
        $this->mbiterable = false;
        $this->serializer = new RequestSerializer();
    }

    /**
     * {@inheritdoc}
     */
    public function write(ComposableConnectionInterface $connection, CommandInterface $command)
    {
        $request = $this->serializer->serialize($command);
        $connection->writeBuffer($request);
    }

    /**
     * {@inheritdoc}
     */
    public function read(ComposableConnectionInterface $connection)
    {
        $chunk = $connection->readLine();
        $prefix = $chunk[0];
        $payload = substr($chunk, 1);

        switch ($prefix) {
            case '+':    // inline
                return new StatusResponse($payload);

            case '$':    // bulk
                $size = (int) $payload;
                if ($size === -1) {
                    return null;
                }
                return substr($connection->readBuffer($size + 2), 0, -2);

            case '*':    // multi bulk
                $count = (int) $payload;

                if ($count === -1) {
                    return null;
                }
                if ($this->mbiterable) {
                    return new MultiBulkIterator($connection, $count);
                }

                $multibulk = array();

                for ($i = 0; $i < $count; $i++) {
                    $multibulk[$i] = $this->read($connection);
                }

                return $multibulk;

            case ':':    // integer
                return (int) $payload;

            case '-':    // error
                return new ErrorResponse($payload);

            default:
                CommunicationException::handle(new ProtocolException(
                    $connection, "Unknown prefix: '$prefix'"
                ));
        }
    }

    /**
     * Enables or disables returning multibulk responses as specialized PHP
     * iterators used to stream bulk elements of a multibulk response instead
     * returning a plain array.
     *
     * Streamable multibulk responses are not globally supported by the
     * abstractions built-in into Predis, such as transactions or pipelines.
     * Use them with care!
     *
     * @param bool $value Enable or disable streamable multibulk responses.
     */
    public function useIterableMultibulk($value)
    {
        $this->mbiterable = (bool) $value;
    }
}
