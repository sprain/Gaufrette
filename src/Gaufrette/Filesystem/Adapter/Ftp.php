<?php

namespace Gaufrette\Filesystem\Adapter;

use Gaufrette\Filesystem\Adapter;

/**
 * Ftp adapter
 *
 * This adapter is not cached, if you need it to be cached, please see the
 * CachedFtp adapter which is a proxy class implementing a cache layer.
 *
 * @packageGaufrette
 * @author  Antoine Hérault <antoine.herault@gmail.com>
 */
class Ftp implements Adapter
{
    protected $connection = null;
    protected $directory;
    protected $host;
    protected $port;
    protected $username;
    protected $password;
    protected $passive;
    protected $create;

    /**
     * Constructor
     *
     * @param  string $directory The directory to use in the ftp server
     * @param  string $host      The host of the ftp server
     * @param  string $username  The username
     * @param  string $password  The password
     * @param  string $port      The ftp port (default 21)
     * @param  string $passive   Whether to switch the ftp connection in passive
     *                           mode (default FALSE)
     * @param  string $create    Whether to create the directory if it does not
     *                           exist
     */
    public function __construct($directory, $host, $username = null, $password = null, $port = 21, $passive = false, $create = false)
    {
        $this->directory = $directory;
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->passive = $passive;
        $this->create = $create;
    }

    /**
     * {@InheritDoc}
     */
    public function read($key)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $temp = fopen('php://temp', 'r+');

        if (!ftp_fget($this->connection, $temp, $this->computePath($key), FTP_ASCII)) {
            throw new \RuntimeException(sprintf('Could not read file \'%s\'.', $key));
        }

        rewind($temp);
        $contents = stream_get_contents($temp);
        fclose($temp);

        return $contents;
    }

    /**
     * {@InheritDoc}
     */
    public function write($key, $content)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $path = $this->computePath($key);
        $directory = dirname($path);

        $this->ensureDirectoryExists($directory, true);

        $temp = fopen('php://temp', 'r+');
        $size = fwrite($temp, $content);
        rewind($temp);

        if (!ftp_fput($this->connection, $path, $temp, FTP_ASCII)) {
            throw new \RuntimeException(sprintf('Could not write file \'%s\'.', $key));
        }

        fclose($temp);

        return $size;
    }

    /**
     * {@InheritDoc}
     */
    public function exists($key)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $files = ftp_nlist($this->connection, dirname($this->computePath($key)));
        foreach ($files as $file) {
            if ($key === $file) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@InheritDoc}
     */
    public function keys($pattern)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $keys = $this->listDirectory($pattern);

        return $keys;
    }

    /**
     * {@InheritDoc}
     */
    public function mtime($key)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $mtime = ftp_mdtm($this->connection, $this->computePath($key));

        // the server does not support this function
        if (-1 === $mtime) {
            throw new \RuntimeException(sprintf('Could not get the last modified time of the \'%s\' file.', $key));
        }

        return $mtime;
    }

    /**
     * {@InheritDoc}
     */
    public function delete($key)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        return ftp_delete($this->connection, $this->computePath($key));
    }

    /**
     * Ensures the specified directory exists. If it does not, and the create
     * parameter is set to TRUE, it tries to create it
     *
     * @param  string  $directory
     * @param  boolean $create Whether to create the directory if it does not
     *                         exist
     *
     * @throws RuntimeException if the directory does not exist and could not
     *                          be created
     */
    public function ensureDirectoryExists($directory, $create = false)
    {
        if (!$this->directoryExists($directory)) {
            if (!$create) {
                throw new \RuntimeException(sprintf('The directory \'%s\' does not exist.', $directory));
            }

            $this->createDirectory($directory);
        }
    }

    /**
     * Indicates whether the specified directory exists
     *
     * @param  string $directory
     *
     * @return boolean TRUE if the directory exists, FALSE otherwise
     */
    public function directoryExists($directory)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        if (!@ftp_chdir($this->connection, $directory)) {
            return false;
        }

        // change directory again to return in the base directory
        @ftp_chdir($this->connection, $this->directory);

        return true;
    }

    /**
     * Creates the specified directory and its parent directories
     *
     * @param  string $directory Directory to create
     *
     * @throws RuntimeException if the directory could not be created
     */
    public function createDirectory($directory)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        // create parent directory if needed
        $parent = dirname($directory);
        if (!$this->directoryExists($parent)) {
            $this->createDirectory($parent);
        }

        // create the specified directory
        $created = ftp_mkdir($this->connection, $directory);
        if (false === $created) {
            throw new \RuntimeException(sprintf('Could not create the \'%s\' directory.', $directory));
        }
    }

    /**
     * Recursively lists files from the specified directory. If a pattern is
     * specified, it only returns files matching it.
     *
     * @param  string $directory The path of the directory to list files from
     * @param  string $pattern   The pattern that files must match to be
     *                           returned
     *
     * @return array An array of file keys
     */
    public function listDirectory($directory, $pattern = null)
    {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $keys = array();
        $files = ftp_rawlist($this->connection, $directory);
        $files = $this->parseRawlist($files ? : array());

        foreach ($files as $file) {
            if ('-' === substr($file['chmod'], 0, 1)) {
                $keys[] = trim($directory . '/' . $file['name'], '/');
            }
        }

        if (null !== $pattern) {
            $keys = array_filter($keys, function($key) {
                return preg_match(sprintf('/^%s/', preg_quote($pattern, '/')), $key);
            });
        }

        return $keys;
    }

    /**
     * Parses the given raw list
     *
     * @param  array $rawlist
     *
     * @return array
     */
    public function parseRawlist(array $rawlist)
    {
        $parsed = array();
        foreach ($rawlist as $line) {
            $vinfo = preg_split("/[\s]+/", $line, 9);
            if ($vinfo[0] !== "total") {
              $info['chmod'] = $vinfo[0];
              $info['num'] = $vinfo[1];
              $info['owner'] = $vinfo[2];
              $info['group'] = $vinfo[3];
              $info['size'] = $vinfo[4];
              $info['month'] = $vinfo[5];
              $info['day'] = $vinfo[6];
              $info['time'] = $vinfo[7];
              $info['name'] = $vinfo[8];
              $parsed[$info['name']] = $info;
            }
        }

        return $parsed;
    }

    /**
     * Computes the path for the given key
     *
     * @param  string $key
     *
     * @todo Rename this method (is it really mandatory)
     */
    public function computePath($key)
    {
        return $key;
    }

    /**
     * Indicates whether the adapter has an open ftp connection
     *
     * @return boolean
     */
    public function isConnected()
    {
        return is_resource($this->connection);
    }

    /**
     * Opens the adapter's ftp connection
     *
     * @throws RuntimeException if could not connect
     */
    public function connect()
    {
        // open ftp connection
        $this->connection = ftp_connect($this->host, $this->port);
        if (!$this->connection) {
            throw new \RuntimeException(sprintf('Could not connect to \'%s\' (port: %s).', $this->host, $this->port));
        }

        $username = $this->username ? : 'anonymous';
        $password = $this->password ? : '';

        // login ftp user
        if (!ftp_login($this->connection, $username, $password)) {
            $this->close();
            throw new \RuntimeException(sprintf('Could not login as %s.', $this->username));
        }

        // switch to passive mode if needed
        if ($this->passive && !ftp_pasv($this->connection, true)) {
            $this->close();
            throw new \RuntimeException('Could not turn passive mode on.');
        }

        // ensure the adapter's directory exists
        if (!empty($this->directory)) {
            try {
                $this->ensureDirectoryExists($this->directory, $this->create);
            } catch (\RuntimeException $e) {
                $this->close();
                throw $e;
            }

            // change the current directory for the adapter's directory
            if (!ftp_chdir($this->connection, $this->directory)) {
                $this->close();
                throw new \RuntimeException(sprintf('Could not change current directory for the \'%s\' directory.', $this->directory));
            }
        }
    }

    /**
     * Closes the adapter's ftp connection
     */
    public function close()
    {
        if ($this->isConnected()) {
            ftp_close($this->connection);
        }
    }
}