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
    private $_debug = false;
    private $_compareHashes = false;
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

    function setDebug($debug = true) 
    {
        $this->_debug = $debug; 
        return $this;
    }

    function setVerbose($verbose = true) 
    {
        $this->_verbose = $verbose;
        return $this;
    }

    function setInfo($info = true) 
    {
        $this->_info = $info;
        return $this;
    }

    function setLargeFileSize($size)
    {
        $this->_largeFileSize = $size;
        return $this;
    }

    function setMultiPartSize($size)
    {
        $this->_multiPartSize = $size;
        return $this;
    }

    function setCompareHashes($compare = true) 
    {
        $this->_compareHashes = $compare;
        return $this;
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
                $remote[$filename] = $head['Metadata'];
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
            //skip directories with "/Trash/" in the name
            if (strpos($entry, "/Trash") !== false)
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
                //TODO compare modified date, and if different then compare hash

                //found a file, get its properties to identify its contents
                $filename = "$dir/$entry";
                $fileSize = filesize($filename);
                $key = substr($filename, strlen($this->_root)+1);
                $modified = filemtime($filename);
                $local[$key] = array(
                    //'hash'=>$hash,
                    'modified'=>date("Y-m-d H:i:s", $modified),
                    'size'=>$fileSize,
                );
                if ($this->_compareHashes)
                {   
                    if ($this->_verbose) echo "Getting hash for $filename \n";
                    $hash = hash_file("md5", $filename); 
                    $local[$key]['hash'] = $hash;
                }
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
        return $this;
    }
                                                                                                                                                                               
    function addFiles($files)
    {  
        foreach ($files as $key=>$item)
        {  
            $this->putFile($key, $item);
        }
        return $this;
    }

    function putFile($key, $item)
    {  
        //Store full name, including containing directory
        $filename = $this->_root . "/" . $key;
        $fileSize = filesize($filename);
        $multipart = false;
        if ($fileSize >= $this->_largeFileSize)
        {  
            $multipart = true;
        }
        if ($this->_compareHashes)
        {  
            if (!array_key_exists('hash', $item))
            {  
                if ($this->_verbose) echo "Getting hash for $filename \n";
                $hash = hash_file("md5", $filename);
                $item = array_merge($item, array('hash'=>$hash));
            }
        }
        $metadata = $item['metadata'];
        if ($this->_debug) echo "metadata:\n";
        if ($this->_debug) print_r($metadata);
        $fr = fopen($filename, 'r');
        if (!$multipart)
        {  
            if ($this->_info) echo "\nUploading $key...";
            $this->_client->putObject(array(
                'Bucket'=>$this->_bucket,
                'Key'=>$key,
                'Body'=>$fr,
                //'Metadata'=>array('hash'=>$hash,),
                'Metadata'=>$metadata,
            ));
            if ($this->_info) echo "Complete\n";
        }
        else
        { //multipart
            //TODO take advantage of the ability to upload multiple parts in parallel
            $result = $this->_client->CreateMultipartUpload(array(
                'Bucket'=>$this->_bucket,
                'Key'=>$key,
                'Metadata'=>$metadata,
            ));
            $uploadId = $result['UploadId'];
            $position = 0;
            $part = 1;
            $partSize = $this->_multiPartSize;
            $parts = (ceil($fileSize / $partSize));
            if ($this->_verbose) echo "\nUploading " . round(10*$fileSize/1024/1024)/10 . " MB file. \n";
            echo "\n";
            while ($position <= $fileSize)
            {  
                $readLength = $partSize;
                if ($position+$readLength >= $fileSize)
                {  
                    $readLength = $fileSize - $position;
                }
                if ($this->_info) echo "Uploading $key, part $part of $parts, $readLength bytes...";
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

    function filesDifferent($a, $b)
    {  
        //TODO files should count as equal if hash matches but modified doesn't
        $keysToCompare = array('modified');
        if ($this->_compareHashes)
        {  
            $keysToCompare[] = 'hash';
        }
        $diff = array();
        foreach ($a as $key=>$item)
        {  
            //check if file in a exists in b
            if (array_key_exists($key, $b))
            { //file does exist
                foreach ($keysToCompare as $criteria)
                {  
                    if (!array_key_exists($criteria,$b[$key]) || !array_key_exists($criteria,$item) || $b[$key][$criteria] !== $item[$criteria])
                    {  
                        $diff[$key] = array(
                            'metadata'=>$item,
                            "action"=>"Update",
                            "reason"=>"'$criteria' does not match",
                        );
                    }
                }
                //if ($b[$key] !== $item) 
                //{ //contents different
                    //$diff[] = $key;
                //}
            }
            else
            { //file does not exist
                //$diff[] = $key;
                $diff[$key] = array(
                    'metadata'=>$item,
                    "action"=>"Add",
                );
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
                $diff[$key] = array(
                    'metadata'=>$item,
                    "action"=>"Delete",
                );
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

    function findSame($local, $remote)
    {  
        $diff = $this->filesDifferent($local, $remote);
        $keep = array_diff_assoc($remote, $diff);
        return $keep;
    }


    function findAddable($local, $remote)
    {  
        return $this->filesDifferent($local, $remote);
    }

}
