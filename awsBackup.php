<?php

require "aws.phar";

use Aws\S3\S3Client;

class AwsBackup
{

	private $_client;
	private $_bucket;
	private $_root;
	private $_verbose = false;
	private $_info = false;
	private $_largeFileSize = 20971520; //20MB
	private $_multiPartSize = 20971520; //20MB

	function __construct($root, $bucket, $key, $secret)
	{
		$this->_root = $root;
		$this->_bucket = $bucket;
		$client = S3Client::factory(array(
			'key' => $key,
			'secret' => $secret,
		));
		$this->_client = $client;
	}

	function setVerbose($verbose = true)
	{
		$this->_verbose = $verbose;
	}

	function setInfo($info = true)
	{
		$this->_info = $info;
	}

	function setLargeFileSize($size)
	{
		$this->_largeFileSize = $size;
	}

	function setMultiPartSize($size)
	{
		$this->_multiPartSize = $size;
	}

	//get a list of objects in the bucket
	function getRemote()
	{
		$remote = array();
		$objects = $this->_client->listObjects(array(
			'Bucket'=>$this->_bucket
		));

		foreach ($objects['Contents'] as $content)
		{
			#if ($content['Size'] !== '0') //skip 0 length objects
			#{
				$filename = $content['Key'];
				$head = $this->_client->headObject(array(
					'Bucket'=>$this->_bucket,
					'Key'=>$content['Key'],
				));
				$remote[$filename] = array(
					'hash'=>$head['Metadata']['hash'],
				);
			#}
		}
		return $remote;
	}

	//get a list of files in the ROOT directory
	function getLocal()
	{
		$local = array();
		$this->listDir($this->_root, $local);
		return $local;
	}

	//recursively list files
	function listDir ($dir, &$local)
	{
		if ($this->_verbose) echo "Scanning directory $dir\n";
		$entries = scandir($dir);
		foreach ($entries as $entry)
		{
			//skip current and parent directory entries
			if ($entry === "." || $entry === "..")
			{
				continue;
			}

			if (is_dir($dir . "/" . $entry))
			{
				//its a directory, so recurse
				$this->listDir($dir . "/" . $entry, $local);
			}
			else
			{
				//TODO make this faster, maybe compare modified date, and if different then compare hash

				//found a file, calculate its hash to identify its contents
				$filename = "$dir/$entry";
				if ($this->_verbose) echo "Getting hash for $filename \n";
				$key = substr($filename, strlen($this->_root)+1);
				$hash = hash_file("md5", $filename); 
				$local[$key] = array(
					'hash'=>$hash,
				);
			}
		}
	}

	function deleteFiles($files)
	{
		foreach ($files as $key=>$item)
		{
			$this->_client->deleteObject(array(
				'Bucket'=>$this->_bucket,
				'Key'=>$key,
			));
		}
	}

	function addFiles($files)
	{
		foreach ($files as $key=>$item)
		{
			$this->putFile($key, $item['hash']);
		}
	}

	function putFile($key, $hash)
	{
		//Store full name, including containing directory
		$filename = $this->_root . "/" . $key;
		$fileSize = filesize($filename);
		$multipart = false;
		if ($fileSize >= $this->_largeFileSize)
		{
			$multipart = true;
		}

		$fr = fopen($filename, 'r');
		if (!$multipart)
		{
			if ($this->_info) echo "\nUploading $key...";
			$this->_client->putObject(array(
				'Bucket'=>$this->_bucket,
				'Key'=>$key,
				'Body'=>$fr,
				'Metadata'=>array('hash'=>$hash,),
			));
			if ($this->_info) echo "Complete\n";
		}
		else
		{ //multipart
			//TODO take advantage of the ability to upload multiple parts in parallel
			$result = $this->_client->CreateMultipartUpload(array(
				'Bucket'=>$this->_bucket,
				'Key'=>$key,
				'Metadata'=>array('hash'=>$hash,),
			));
			$uploadId = $result['UploadId'];
			$position = 0;
			$part = 1;
			$partSize = $this->_multiPartSize;
			$parts = (ceil($fileSize / $partSize));
			while ($position <= $fileSize)
			{
				$readLength = $partSize;
				if ($position+$readLength >= $fileSize)
				{
					$readLength = $fileSize - $position;
				}
				if ($this->_info) echo "\nUploading $key, part $part of $parts, $readLength bytes...";
				//if ($this->_verbose) echo "\nUploading $readLength byte part $part of $parts, position $position of $fileSize";
				$this->_client->uploadPart(array(
					'Bucket'=>$this->_bucket,
					'Key'=>$key,
					'UploadId'=>$uploadId,
					'PartNumber'=>$part,
					'Body'=>fread($fr, $partSize),
				));
				if ($this->_info) echo "Complete\n";
				$position += $partSize;
				$part++;
			}
			$uploadedParts = $this->_client->listParts(array(
				'Bucket'=>$this->_bucket,
				'Key'=>$key,
				'UploadId'=>$uploadId,
			));

			$this->_client->completeMultipartUpload(array(
				'Bucket'=>$this->_bucket,
				'Key'=>$key,
				'UploadId'=>$uploadId,
				'Parts'=>$uploadedParts['Parts'],
			));
			if ($this->_verbose) echo "Finished put for $key\n\n";
			
		}
		fclose($fr);
	}

	function filesEqual($a, $b)
	{
		$diff = array();
		foreach ($a as $key=>$item)
		{
			//check if file in a exists in b
			if (array_key_exists($key, $b)) 
			{ //file does exist
				if ($b[$key] !== $item) 
				{ //contents different
					//$diff[] = $key;
					$diff[$key] = array_merge($item, array(
						"action"=>"Update",
					));
				}
			}
			else
			{ //file does not exist
				//$diff[] = $key;
				$diff[$key] = array_merge($item, array(
					"action"=>"Add",
				));
			}
		}
		return $diff;
	}

	function filesExist($a, $b)
	{
		$diff = array();
		foreach ($a as $key=>$item)
		{
			//check if file in a exists in b
			if (!array_key_exists($key, $b)) 
			{ //file does not exist
				$diff[$key] = array_merge($item, array(
					"action"=>"Delete",
				));
			}
		}
		return $diff;
	}
	
	function sortByAction($a, $b)
	{
		if ($a['action'] > $b['action'])
		{
			return 1;
		}
		elseif ($a['action'] < $b['action'])
		{
			return -1;
		}
		return 0;
	}

	function findRemovable($local, $remote)
	{
		return $this->filesExist($remote, $local);		
	}

	function findAddable($local, $remote)
	{
		return $this->filesEqual($local, $remote);		
	}

}
