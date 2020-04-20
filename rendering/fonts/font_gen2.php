<?php

class Font
{
  public function GenerateOutput(
    // 8 bits per scanline, Height bytes per character
    // I.e. bytes for character N are N*height to (N+1)*height
    $bitmap,
    // Width and height
    $width, $height,
    $revmap)
  {
    $n = count($bitmap);
    print "static const unsigned char bitmap[$n] = {\n";
    $n=0;
    $chno=0;
    #print_r($unicode_map);
    $bytes_x = ($width+7) >> 3;
    foreach($bitmap as $value)
    {
      printf("0x%02X,", $value);
      if(++$n == $height * $bytes_x)
      {
        $n=0;
        $s = Array();
        foreach($revmap as $u=>$v) if($v==$chno) $s[] = sprintf('U+%04X', $u);
        printf(" /* %02X (%s) */\n", $chno, join(', ', $s));
        #print "\n";
        ++$chno;
      }
    }
    print "};\n";
    
    ksort($revmap);
    $min = min(array_keys($revmap));
    $max = max(array_keys($revmap));
    $values = Array();
    $maxval = max(array_values($revmap));
    $qsym = 0xFFFD;
    if(!isset($revmap[$qsym])) $qsym = ord('?');
    $qmark = $revmap[$qsym];
    for($n=$min; $n<=$max; ++$n)
    {
      if(isset($revmap[$n]))
        $values[$n-$min] = $revmap[$n];
      else
        $values[$n-$min] = $qmark;
    }
    ksort($values);

    $condition = ($min > 0) ? "c >= $min && c <= $max" : "c <= $max";

    /*
    $type = 'unsigned';
    if($maxval < 65536) $type = 'std::uint_least16_t';
    if($maxval < 256)   $type = 'std::uint_least8_t';

    printf("static const %s trans[%u] = { %s };\n",
      $type, $max-$min+1, join(', ', $values));

    printf("unsigned unicode_to_bitmap_index(char32_t c)\n".
           "{\n".
           "    return ($condition) ? trans[c-$min] : 0;\n".
           "}\n");
    */
    /*
    $p = proc_open('./constablecom',
                   [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', 'php://stderr', 'w']],
                   $pipes);
    fwrite($pipes[0], $table);
    fclose($pipes[0]);
    print stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($p);
    */

    $cache_fn = "data/.lookup-".md5(serialize($values));
    if(file_exists($cache_fn) && filesize($cache_fn) > 0)
    {
      file_put_contents("php://stderr", "Cache hit: $cache_fn for {$width}x{$height}\n");
      #$contents = file_get_contents('compress.zlib://'.$cache_fn);
      $contents = file_get_contents($cache_fn);
    }
    else
    {
      file_put_contents("php://stderr", "Cache miss: $cache_fn for {$width}x{$height}\n");
      $fp = fopen($cache_fn, 'w+');
      if(flock($fp, LOCK_EX))
      {
        ftruncate($fp, 0);
        $count = count($values);
        $p = proc_open("./table-packer lookup {$width}x{$height} $count $cache_fn",
                       [0=>['pipe','r'], 1=>['pipe','w'], 2=>['file', 'php://stderr', 'w']],
                       $pipes);
        foreach($values as $v) fwrite($pipes[0], "$v\n");
        fclose($pipes[0]);
        $contents = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($p);
        #print $contents;
        /*
        $contents = preg_replace('@//.*@',      '', $contents);
        $contents = preg_replace("@\s+\$@",   '', $contents);
        $contents = preg_replace("@^\s+@",   '', $contents);
        $contents = preg_replace("@\n+@",    ' ', $contents);
        $contents = preg_replace("@\s{2,}@", ' ', $contents);
        $contents = preg_replace("@ *#@", "\n#", $contents);
        $contents = preg_replace("@#endif\s@", "#endif\n", $contents);
        $contents = preg_replace("@__ __@", "__\n__", $contents);
        */
        fwrite($fp, $contents);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
      }
      else
      {
        file_put_contents("php://stderr", "Could not lock $cache_fn, assuming it is being worked on by another task\n");
      }
    }

    printf("#include \"fonts/%s\"\n", $cache_fn);
    printf("std::pair<unsigned,bool> unicode_to_bitmap_index(char32_t c)\n".
           "{\n".
           "    if($condition) { unsigned r = lookup(c- $min); return {r, r != $qmark}; }\n".
           "    return {{$qmark}, false};\n".
           "}\n");
  }
};
