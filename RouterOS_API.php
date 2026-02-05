<?php
/**
 * RouterOS API Class
 * PHP class to interact with MikroTik RouterOS API
 */

class RouterosAPI {
    
    public $debug = false;
    public $connected = false;
    public $timeout = 3;
    public $attempts = 2;
    public $delay = 1;
    
    private $socket;
    private $error_no;
    private $error_str;
    
    /**
     * Connect to RouterOS
     */
    public function connect($ip, $login, $password, $port = 8728) {
        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            $this->connected = false;
            
            $this->socket = @fsockopen($ip, $port, $this->error_no, $this->error_str, $this->timeout);
            
            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                
                if ($this->login($login, $password)) {
                    $this->connected = true;
                    return true;
                }
                
                fclose($this->socket);
            }
            
            sleep($this->delay);
        }
        
        return false;
    }
    
    /**
     * Disconnect from RouterOS
     */
    public function disconnect() {
        if ($this->connected && $this->socket) {
            @stream_socket_shutdown($this->socket, STREAM_SHUT_RDWR);
            @fclose($this->socket);
            $this->socket = null;
            $this->connected = false;
        }
    }
    
    /**
     * Login to RouterOS
     */
    private function login($login, $password) {
        // Try new auth method first (RouterOS 6.43+)
        $this->write('/login', false);
        $this->write('=name=' . $login, false);
        $this->write('=password=' . $password);
        $response = $this->read(false);
        
        if (isset($response[0]) && $response[0] === '!done') {
            return true;
        }
        
        // Try old auth method
        if (isset($response[0]) && $response[0] === '!done' && isset($response[1])) {
            if (preg_match('/=ret=(.*)/', $response[1], $matches)) {
                $challenge = pack('H*', $matches[1]);
                $hash = md5(chr(0) . $password . $challenge);
                
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=response=00' . $hash);
                $response = $this->read(false);
                
                if (isset($response[0]) && $response[0] === '!done') {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Execute command
     */
    public function comm($com, $arr = []) {
        $count = count($arr);
        
        if ($count > 0) {
            $this->write($com, false);
            $i = 0;
            foreach ($arr as $key => $value) {
                $i++;
                $this->write('=' . $key . '=' . $value, ($i == $count));
            }
        } else {
            $this->write($com);
        }
        
        return $this->parseResponse($this->read());
    }
    
    /**
     * Write to socket
     */
    private function write($command, $endSentence = true) {
        $this->writeWord($command);
        
        if ($endSentence) {
            $this->writeWord('');
        }
    }
    
    /**
     * Write word to socket
     */
    private function writeWord($word) {
        $len = strlen($word);
        
        if ($len < 0x80) {
            fwrite($this->socket, chr($len));
        } elseif ($len < 0x4000) {
            $len |= 0x8000;
            fwrite($this->socket, chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x200000) {
            $len |= 0xC00000;
            fwrite($this->socket, chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } elseif ($len < 0x10000000) {
            $len |= 0xE0000000;
            fwrite($this->socket, chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        } else {
            fwrite($this->socket, chr(0xF0) . chr(($len >> 24) & 0xFF) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF));
        }
        
        fwrite($this->socket, $word);
        
        if ($this->debug) {
            echo ">>> $word\n";
        }
    }
    
    /**
     * Read from socket
     */
    private function read($parse = true) {
        $response = [];
        
        while (true) {
            $word = $this->readWord();
            
            if ($word === '') {
                if (end($response) === '!done') {
                    break;
                }
            } else {
                $response[] = $word;
                
                if ($this->debug) {
                    echo "<<< $word\n";
                }
            }
        }
        
        return $response;
    }
    
    /**
     * Read word from socket
     */
    private function readWord() {
        $byte = ord(fread($this->socket, 1));
        
        if ($byte & 0x80) {
            if (($byte & 0xC0) === 0x80) {
                $len = (($byte & ~0x80) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xE0) === 0xC0) {
                $len = (($byte & ~0xC0) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif (($byte & 0xF0) === 0xE0) {
                $len = (($byte & ~0xE0) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            } elseif ($byte === 0xF0) {
                $len = (ord(fread($this->socket, 1)) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
            }
        } else {
            $len = $byte;
        }
        
        if ($len > 0) {
            $ret = '';
            while ($len > 0) {
                $read = fread($this->socket, $len);
                $len -= strlen($read);
                $ret .= $read;
            }
            return $ret;
        }
        
        return '';
    }
    
    /**
     * Parse response
     */
    private function parseResponse($response) {
        $parsed = [];
        $current = null;
        $trap = false;
        
        foreach ($response as $word) {
            if (in_array($word, ['!done', '!re', '!fatal', '!trap'])) {
                if ($word === '!trap') {
                    $trap = true;
                }
                if ($current !== null) {
                    $parsed[] = $current;
                }
                $current = [];
            } elseif (preg_match('/^=(.+?)=(.*)$/', $word, $matches)) {
                if ($current !== null) {
                    $current[$matches[1]] = $matches[2];
                }
            }
        }
        
        if ($current !== null && count($current) > 0) {
            $parsed[] = $current;
        }
        
        if ($trap) {
            return ['!trap' => $parsed];
        }
        
        return $parsed;
    }
}
