<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class File extends IOStream {
	public $priority = 10; // low priority
	public $pos = 0;
	public $chunkSize = 4096;
	public $stat;

	public static function convertFlags($mode) {
		$plus = strpos($mode, '+') !== false;
		$sync = strpos($mode, 's') !== false;
		$type = strtr($mode, array('b' => '', '+' => '', 's' => ''));
		$types = array(
			'r' =>  $plus ? EIO_O_RDWR : EIO_O_RDONLY,
			'w' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_TRUNC,
			'a' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_APPEND,
			'x' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_EXCL | EIO_O_CREAT,
			'c' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT,
		);
		$m = $types[$type];
		if ($sync) {
			$m |= EIO_O_FSYNC;
		}
		return $m;
	}


	public function truncate($offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$fp = fopen($this->path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return;
		}
		eio_ftruncate($this->fd, $offset, $pri, $cb, $this);
	}
	
	public function stat($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, FS::statPrepare(fstat($this->fd)));
		}
		if ($this->stat) {
			call_user_func($cb, $this, $this->stat);
		} else {
			eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
				$stat = FS::statPrepare($stat);
				$file->stat = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}
	
	public function statvfs($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, false);
		}
		if ($this->statvfs) {
			call_user_func($cb, $this, $this->statvfs);
		} else {
			eio_fstatvfs($this->fd, $pri, function ($file, $stat) use ($cb) {
				$file->statvfs = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}

	public function sync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return;
		}
		eio_fsync($this->fd, $pri, $cb, $this);
	}
	
	public function datasync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return;
		}
		eio_fdatasync($this->fd, $pri, $cb, $this);
	}

	public function chown($uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = chown($path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($path, $gid);
			}
			call_user_func($cb, $this, $r);
			return;
		}
		eio_fchown($this->fd, $uid, $gid, $pri, $cb, $this);
	}
	
	public function touch($mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = touch($this->path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return;
		}
		eio_futime($this->fd, $atime, $mtime, $pri, $cb, $this);
	}
	

	public function clearStatCache() {
		$this->stat = null;
		$this->statvfs = null;
	}
	
	public function read($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$cb && !$this->onRead) {
			return false;
		}
		$this->pos += $length;
		$file = $this;
		eio_read(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->pos,
			$pri,
			$cb ? $cb: $this->onRead,
			$this
		);
		return true;
	}
	
	public function readahead($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$cb && !$this->onRead) {
			return false;
		}
		$this->pos += $length;
		$file = $this;
		eio_read(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->pos,
			$pri,
			$cb ? $cb: $this->onRead,
			$this
		);
		return true;
	}

	public function readAll($cb = null, $pri = EIO_PRI_DEFAULT) {
		$this->stat(function ($file, $stat) use ($cb, $pri) {
			if (!$stat) {
				call_user_func($cb, $file, false);
				return;
			}
			$offset = 0;
			$buf = '';
			$size = $stat['st_size'];
			$handler = function ($file, $data) use ($cb, &$handler, $stat, &$offset, $pri, &$buf) {
				$buf .= $data;
				$offset += strlen($data);
				$len = min($this->chunkSize, $size - $offset);
				if ($offset >= $size) {
					call_user_func($cb, $file, $buf);
					return;
				}
				eio_read($this->fd, $len, $offset, $pri, $handler, $this);
			};
			eio_read($this->fd, min($this->chunkSize, $size), 0, $pri, $handler, $this);
		}, $pri);
	}
	
	public function readAllChunked($cb = null, $chunkcb = null, $pri = EIO_PRI_DEFAULT) {
		$this->stat(function ($file, $stat) use ($cb, $chunkcb, $pri) {
			if (!$stat) {
				call_user_func($cb, $file, false);
				return;
			}
			$offset = 0;
			$size = $stat['st_size'];
			$handler = function ($file, $data) use ($cb, $chunkcb, &$handler, $size, &$offset, $pri) {
				call_user_func($chunkcb, $file, $data);
				$offset += strlen($data);
				$len = min($this->chunkSize, $size - $offset);
				if ($offset >= $stat['st_size']) {
					call_user_func($cb, $file, true);
					return;
				}
				eio_read($this->fd, $len, $offset, $pri, $handler, $this);
			};
			eio_read($this->fd, min($this->chunkSize, $size), $offset, $pri, $handler, $this);
		}, $pri);
	}
	public function __toString() {
		return $this->path;
	}
	public function setChunkSize($n) {
		$this->chunkSize = $n;
	}
	
	public function setFd($fd) {
		$this->fd = $fd;
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}
	
	public function seek($p) {
		if (EIO::$supported) {
			$this->pos = $p;
			return true;
		}
		fseek($this->fd, $p);
	}
	public function tell() {
		if (EIO::$supported) {
			return $this->pos;
		}
		return ftell($this->fd);
	}
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	/*public function read($n) {
		if (isset($this->readEvent)) {
			if (!isset($this->fd)) {
				return false;
			}
			$read = fread($this->fd, $n);
		} else {
			if (!isset($this->buffer)) {
				return false;
			}
			$read = event_buffer_read($this->buffer, $n);
		}
		if (
			($read === '') 
			|| ($read === null) 
			|| ($read === false)
		) {
			$this->reading = false;
			return false;
		}
		return $read;
	}*/
	
	public function close() {
		$this->closeFd();
	}
	public function closeFd() {
		if (FS::$supported) {
			eio_close($this->fd);
			$this->fd = null;
			return;
		}
		fclose($this->fd);
	}
	
	public function eof() {
		if (
			!$this->EOF && (
				($this->readFD === FALSE) 
				|| feof($this->readFD)
			)
		) {
			$this->onEofEvent();
		}
		elseif (!$this->EOF) {
			$this->onReadEvent();
		}

		return $this->EOF;
	}

	public function onEofEvent() {
		$this->EOF = true;
	
		if ($this->onEOF !== NULL) {
			call_user_func($this->onEOF, $this);
		}
	
		$this->close();
	}
}
