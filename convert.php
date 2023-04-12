<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
//header("Content-type: text/plain");

// The goal of this script is to convert a .js addon file for p5 to a .d.ts file

// The .js file
//$url = "https://raw.githubusercontent.com/quinton-ashley/p5play/main/p5play.js";
$url = __DIR__ . "/play_raw.js";

// Get the contents of the file
$contents = @file_get_contents($url) . '';

if(!$contents) {
    throw new Exception("Could not get contents of $url");
}

// First step is to remove all the "//" comments
$contents = preg_replace("/^[ \t]*\/\/.*$(\r\n|\r|\n)?/m", "", $contents);

// Now lets remove everything outside "p5.prototype.registerMethod('init', function p5PlayInit() {"
$indexes = getBlockIndexes("p5.prototype.registerMethod('init', function p5PlayInit() {", $contents);
$contents = substr($contents, $indexes['start_end'], $indexes['inner_length']);

// Split the contents into blocks
$blocks = splitIntoBlocks($contents);

// Now lets remove all the unnecessary blocks
$blocks = array_filter($blocks, 'shouldKeepBlock');

// Transform blocks into a format consumable by typescript
$contents = transformBlocksIntoString($blocks);

// Now lets save the result
file_put_contents(__DIR__ . "/p5.play.js", $contents);

die("Done.\nNow run: `tsc --declaration --allowJs --emitDeclarationOnly p5.play.js`");

function getBlockIndexes(string $start, string $contents): array
{
    $needleLength = strlen($start);

    $start = strpos($contents, $start);

    // strpos using a regexp
    preg_match('/^}\);/m', $contents, $matches, PREG_OFFSET_CAPTURE, $start);

    $return = [
        'start_start' => $start,
        'start_end'   => $start + $needleLength,
        'end_start'   => $matches[0][1],
        'end_end'     => strlen($matches[0][0]) + $matches[0][1],
    ];

    $return['outer_length'] = $return['end_end'] - $return['start_start'];
    $return['inner_length'] = $return['end_start'] - $return['start_end'];

    return $return;
}

function splitIntoBlocks(string $contents): array
{
    $lines = explode(PHP_EOL, $contents);

    $naiveBlocks = [];

    for ($i = 0; $i < count($lines); $i++) {

        // Skip empty lines at the start of blocks
        if (trim($lines[$i]) === "") {
            continue;
        }

        $block = [];

        while (trim($lines[$i] ?? '') !== "") {
            $block[] = $lines[$i];
            $i++;
        }

        $naiveBlocks[] = implode(PHP_EOL, $block);
    }


    return joinNaiveBlocks($naiveBlocks);
}

function joinNaiveBlocks(array $naiveBlocks): array
{

    $return = [];

    for ($i = 0; $i < count($naiveBlocks); $i++) {

        $string = $naiveBlocks[$i];

        // While the next block doesn't begin with 4 spaces then a character
        // Join it to the current block
        while (isset($naiveBlocks[$i + 1])
            && !preg_match("/^( {4}[^ ]| {5}\* )/", $naiveBlocks[$i + 1])
        ) {
            $string .= PHP_EOL . PHP_EOL . $naiveBlocks[$i + 1];
            $i++;
        }

        $return[] = $string;

    }

    return $return;

}

function shouldKeepBlock(string $block): bool
{

    // Get the declaration line of the block EG "this.Group = class extends Array {"
    $declarationLine = getDeclarationLineOfBlock($block);

    if (
        // This block shouldn't be kept if it begins with "this.p5play"
        str_starts_with($declarationLine, "this.p5play") ||

        // This block shouldn't be kept if it begins with "this.angleMode("
        str_starts_with($declarationLine, "this.angleMode(")

        // Remove the Turtle class
//        || str_starts_with($declarationLine, "this.Turtle")

        // Remove the SpriteAnimations class
//        || str_starts_with($declarationLine, "this.SpriteAnimations")
    ) {
        return false;
    }

    // This block should be kept if It begins with "this." and the next character isn't an "_"
    if (preg_match('/^this\.[^_]/', $declarationLine)) {
        return true;
    }

    return false;

}

function getDeclarationLineOfBlock(string $block): string
{
    // Split the block into lines
    $lines = explode(PHP_EOL, $block);

    return trim($lines[getDeclarationLineOfBlockI($lines)]);
}

function getDeclarationLineOfBlockI(array $lines): string
{

    // Let's get the first line that isn't part of a block comment
    $i = 0;
    while ($i < count($lines) && preg_match('/^ +(\*|\/\*)/', $lines[$i])) {
        $i++;
    }

    // This is the first line that isn't part of a block comment
    return $i;
}

function transformBlocksIntoString(array $blocks): string
{
    $return = "";

    foreach ($blocks as $block) {

        // Remove all lines that start with "delete this"
        $block = preg_replace("/^[ \t]*delete this.*$(\r\n|\r|\n)?/m", "", $block);

        // Remove the " //end camera class" comment
        $block = str_replace(" //end camera class", "", $block);

        $lines = explode(PHP_EOL, $block);

        $declarationLineI = getDeclarationLineOfBlockI($lines);

        // $block could look like:
        // /**
        //  * Comment
        //  */
        // this.allSprites = new this.Group();
        // OR
        // this.loadImg = this.loadImage = function () {
        // ...
        // OR
        // this.stroke = function () {
        // ...
        // OR
        // this.showAd = (type) => {
        // ...
        // OR
        // this.Sprite = class {
        // ...
        // OR
        // this.Sprite = class extends Array {
        // ...
        // OR
        // this.Sprite.prototype.addAnimation =
        // ...


        // Convert "this.stroke = function () {"
        // To "stroke : function () {"
        $regexp = '/^ {4}this\.([^ ]+) = (function \([^=]*\) \{)$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '    $1: $2', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        // Convert "this.loadImg = this.loadImage = function () {"
        // To "loadImg : function () {" AND "loadImage : function () {"
        $regexp = '/ {4}this\.([^ ]+) = this\.([^ ]+) = (function \(.*\) \{)$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {

            $option1 = preg_replace($regexp, '    $1: $3', $lines[$declarationLineI]);
            $option2 = preg_replace($regexp, '    $2: $3', $lines[$declarationLineI]);

            $lines[$declarationLineI] = $option1;
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';

            $lines[$declarationLineI] = $option2;
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';

            continue;
        }

        // Convert "this.showAd = (type) => {"
        // To "showAd : (type) => {"
        $regexp = '/this\.([^ ]+) = \(([^)]+)\) => \{$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '$1: ($2) => {', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        // Convert "this.Sprite = class {"
        // To "Sprite : class {"
        $regexp = '/this\.([^ ]+) = class ([^$]*)\{$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '$1: class $2{', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        // Convert "this.Sprite.prototype.addAnimation ="
        // To "Sprite.prototype.addAnimation ="
        $regexp = '/this\.([^ ]+)\.prototype\.([^ ]+) =$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            // TODO properly fix this
            continue;
        }

        // Convert "this.allSprites = new this.Group();"
        // To "allSprites : new this.Group()"
        $regexp = '/this\.([^ ]+) = new this\.([^ ]+)\(\);$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '$1: new this.$2()', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        // Convert "this.keyboard = this.kb;"
        // To "keyboard: this.kb"
        $regexp = '/this\.([^ ]+) = this\.([^ ]+);$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '$1: this.$2', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        // Convert "this.getFPS ??= () => this.p5play._fps;"
        // To "getFPS: () => p5play._fps"
        $regexp = '/this\.([^ ]+) \?\?= \(\) => this\.([^ ]+);$/';
        if (preg_match($regexp, $lines[$declarationLineI])) {
            $lines[$declarationLineI] = preg_replace($regexp, '$1: () => this.$2', $lines[$declarationLineI]);
            $return .= PHP_EOL . PHP_EOL . implode(PHP_EOL, $lines);
            $return = rtrim($return, PHP_EOL . ';') . ',';
            continue;
        }

        throw new Exception("Couldn't transform block: " . $block);

    }

    return
//        "import * as pl from \"planck-js\";\n\n" .
        "module.exports = {\n" .
        "    'p5' : {" . rtrim($return, ',') . "\n\n    }\n}";
}
