<?php

require "vendor/autoload.php";

use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

$adapter = new Local(__DIR__);
$filesystem = new Filesystem($adapter);

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$climate = new League\CLImate\CLImate;

$climate->arguments->add([
    'path' => [
        'prefix' => 'p',
        'longPrefix' => 'path',
        'description' => 'Path to the magento project',
    ],
    'database' => [
        'prefix' => 'd',
        'longPrefix' => 'db',
        'description' => 'Database name for the project (credentials will be taken from .env file)',
    ],
    'format' => [
        'prefix' => 'f',
        'longPrefix' => 'format',
        'description' => 'Image format to convert images to',
        'defaultValue' => 'jpg',
    ],
    'execute' => [
        'prefix' => 'e',
        'longPrefix' => 'execute',
        'description' => 'If present the database will be updated on the fly',
        'noValue' => true,
    ],
    'help' => [
        'longPrefix' => 'help',
        'prefix' => '?',
        'description' => 'Prints a usage statement',
        'noValue' => true,
    ],
]);

$climate->arguments->parse();

if ($climate->arguments->defined('help')) {
    $climate->usage();
    exit;
}

$argError = false;

if (!$climate->arguments->get('path')) {
    $climate->error('A path argument is required');
    $argError = true;
}

if (!$climate->arguments->get('database')) {
    $climate->error('A database argument is required');
    $argError = true;
}

if (!$climate->arguments->get('format')) {
    $climate->error('A format argument is required');
    $argError = true;
}

if ($argError) {
    $climate->usage();
    exit;
}

$converter = new \Algm\MagentoImageConverter\Converter(
    $climate->arguments->get('path'),
    $climate->arguments->get('database'),
    $climate->arguments->get('format'),
    $climate->arguments->defined('execute'),
    $filesystem
);

$climate->out('Starting conversion...');

$bytesize = new \Rych\ByteSize\ByteSize;
$totals = [
    'old' => 0,
    'new' => 0,
    'saves' => collect(),
];

$i = 0;

$converter->run(
    function ($oldImage, $newImage) use ($climate, $bytesize, &$totals, &$i) {
        $i++;
        $oldbytes = filesize($oldImage);
        $newbytes = filesize($newImage);

        $totals['old'] += $oldbytes;
        $totals['new'] += $newbytes;

        $oldsize = $bytesize->format($oldbytes);
        $newsize = $bytesize->format($newbytes);

        $diff = $bytesize->format($oldbytes - $newbytes);
        $savedpct = round(100 - ($newbytes / $oldbytes) * 100);
        $totals['saves']->push(
            [
                'orig' => $oldbytes,
                'size' => $oldbytes - $newbytes,
                'pct' => $savedpct,
            ]
        );

        $oldfile = last(explode('/', $oldImage));
        $newfile = last(explode('/', $newImage));

        $climate->info("$i - $oldfile ($oldsize) => $newfile ($newsize) - Saved: $diff ($savedpct%)");
    }
);

$climate->out('Process finished! You may find the output sql in output.sql');

if ($totals['old'] > 0) {
    $oldTotal = $bytesize->format($totals['old']);
    $newTotal = $bytesize->format($totals['new']);
    $diff = $bytesize->format($totals['old'] - $totals['new']);
    $savedpct = round(100 - ($totals['new'] / $totals['old']) * 100);

    $diffavg = $bytesize->format($totals['saves']->avg('size'));
    $sizeavg = $bytesize->format($totals['saves']->avg('orig'));
    $pctavg = round($totals['saves']->avg('pct'));

    $climate->info("Total $i images converted: $oldTotal => $newTotal - Saved: $diff ($savedpct%)");
    $climate->info("Average file size: $sizeavg - Average save per file: $diffavg ($pctavg%)");
}
