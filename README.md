# generating-ts-def-file-for-p5-play
Generating a d.ts file from the p5.play library
How to use:
 1) Download https://raw.githubusercontent.com/quinton-ashley/p5play/main/p5play.js into the `play_raw.js` file
 2) Run the convert script `php convert.php` this will output `p5.play.js`
 3) Run `tsc --declaration --allowJs --emitDeclarationOnly p5.play.js` to generate a .d.ts file from `p5.play.js`
 4) I then had to do some minor manual edits the file until it looked like `out/p5.play.d.ts`
 
 The `p5.play.d.ts` is tested and working in PHPStorm IDE and therefore should work in all JetBrains IDE's and probably other IDE's as well.
