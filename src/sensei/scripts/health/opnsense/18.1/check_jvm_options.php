<?php
   $memory = $argv[1];
   $options = strtolower($argv[2]);
   $size = 0;
   $type = '';
   if (preg_match_all('/^(-xms|-xmx)(\d+)(k|m|g)/', $options, $matches)){
        $size = $matches[2][0];
        $type = $matches[3][0];
        if ($type == 'k')
          $size *= 1024;
        if ($type == 'm')
          $size *= 1024*1024;
        if ($type == 'g')
          $size *= 1024*1024*1024;

   }
   if ($memory > 8000000000 && $size < 2147483648 ){
       echo '1';
       exit(0);
   }

   if ($memory < 8000000000 && $size > 536870912 ){
       echo '2';
       exit(0);
   }
       echo '0';
       exit(0);



