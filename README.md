php-s3-mirror
=============

PHP script for one way file syncing from a Linux directory to an AWS S3 Bucket. 

Disclaimer
-----------
You take full responsibility for everything that happens as a result of this script to any of your files or your AWS account, including any charges that may be incurred.

Inefficiencies
--------------
This script is slow with large files as it calculates the MD5 hash for each one every time the script is run. It does handle large files (>100MB), but does not take advantage of being able to upload multiple parts in parallel. 

Prerequisites
-------------

###1. Have an AWS account with S3 enabled and a bucket to sync to

###2. Install the AWS SDK for PHP (version 2)

Download [aws.phar](http://pear.amazonwebservices.com/get/aws.phar)  or follow the guide at http://docs.aws.amazon.com/awssdkdocsphp2/latest/gettingstartedguide/sdk-php2-installing-the-sdk.html

###3. Find your primary AWS account credentials OR Set up limited AWS account credentials

####Find your primary AWS account credientials
1. Go to https://portal.aws.amazon.com/gp/aws/securityCredentials

####Create limited AWS account credentials
1. Go to https://console.aws.amazon.com/iam/home?#s=Users
2. Create a new user
3. Copy/save the credentials somewhere
4. Go to the "Permissions" tab for the new user
5. Attach a new custom policy
6. Use a policy document like the following, replacing <bucketname> with the name of the bucket you want to use
```
{"Statement": [{"Action": ["s3:ListBucket", "s3:GetObject", "s3:PutObject", "s3:DeleteObject"], "Effect": "Allow", "Resource": ["arn:aws:s3:::<bucketname>/*", "arn:aws:s3:::<bucketname>"]}]}
```


Usage 
-----


###Simplified example usage, replace parameters in backupProcess object creation

```php
<?php

//Set location of awsBackup script. Should be in the same place as aws.phar
require "/opt/aws/awsBackup.php";

//Create new backup process object. 
//Params are (<Directory to mirror>, <Bucket name>, <AWS Access Key ID>, <AWS Secret Access Key>). Edit these to match your values
$backupProcess = new AwsBackup( "/home/user/pictures",  'example-bucket', 'SOMEKEYID',  'SOMEACCESSKEY');                                                                      

//Get list of remote files from S3 bucket
$remote = $backupProcess->getRemote();

//Get list of local files
$local = $backupProcess->getLocal();

//Compare local to remote, files found in remote with no match in local should be deleted
$remove = $backupProcess->findRemovable($local, $remote);

//Compare local to remote, files in local not found in remote should be added. Files with different hash should be updated
$add = $backupProcess->findAddable($local, $remote);

//Finally, perform the actions
$backupProcess->deleteFiles($remove);
$backupProcess->addFiles($add);
```


###More detailed example usage with output to stdout, replace parameters in backupProcess object creation, set verbosity
```php
<?php

//Set location of awsBackup script. Should be in the same place as aws.phar
require "/opt/aws/awsBackup.php";
//Set level of output to be displayed in stdout
$info = true; //edit this if desired
$verbose = false; //edit this if desired
$info |= $verbose; //if verbose, then also info

$directory = "/home/user/pictures"; //edit this
$bucket = 'example-bucket'; //edit this
$key = 'SOMEKEYID'; //edit this
$secret = 'SOMEACCESSKEY'; //edit this
                                                                                                                                                                               
//Create new backup process object. 
//Params are (<Directory to mirror>, <Bucket name>, <AWS Access Key ID>, <AWS Secret Access Key>)
$backupProcess = new AwsBackup( $directory, $bucket, $key, $secret);

//Set backup process verbosity
if ($verbose) $backupProcess->setVerbose();
//or
//$backupProcess->setVerbose($verbose);

//Set multipart file size parameters
$backupProcess->setLargeFileSize(50*1024*1024); //50MB limit before splitting into multiple parts
$backupProcess->setMultiPartSize(25*1024*1024); //25MB size of each split part

//Get list of remote files from S3 bucket
if ($info) echo "Getting a list of remote files \n\n";
$remote = $backupProcess->getRemote();
if ($verbose) echo "\nRemote files:\n";
if ($verbose) print_r($remote);

//Get list of local files
if ($info) echo "Getting a list of local files. This could take a while. \n\n";
$local = $backupProcess->getLocal();
if ($verbose) echo "\n\nLocal files:\n";
if ($verbose) print_r($local);

//Compare local to remote, files found in remote with no match in local should be deleted
$remove = $backupProcess->findRemovable($local, $remote);

//Display list of files to delete
if ($info)
{
	echo "\nFiles to Remove\n\n";
	foreach ($remove as $filename=>$item)
	{
		//echo $item['action'].":$filename\n";
		echo "$filename\n";
	}
	echo "\n";
}
if ($verbose) echo "\n\nFiles to Remove (detailed)\n\n";
if ($verbose) print_r($remove);


//Compare local to remote, files in local not found in remote should be added. Files with different hash should be updated
$add = $backupProcess->findAddable($local, $remote);

//Sort by action
uasort($add, array($backupProcess, 'sortByAction'));

//Display list of files to put
if ($info)
{
	echo "\nFiles to Put\n\n";
	foreach ($add as $filename=>$item)
	{
		echo $filename . " (" . $item['action'] . ")\n";
	}
	echo "\n";
}
if ($verbose) echo "\n\nFiles to Put (detailed)\n\n";
if ($verbose) print_r($add);

//Finally, perform the actions
$backupProcess->deleteFiles($remove);
$backupProcess->addFiles($add);

if ($info) echo "\n\nComplete\n";
```
