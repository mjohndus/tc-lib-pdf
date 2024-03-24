<?php

/**
 * Text.php
 *
 * @since     2002-08-03
 * @category  Library
 * @package   Pdf
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2002-2024 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf
 *
 * This file is part of tc-lib-pdf software library.
 */

namespace Com\Tecnick\Pdf;

use Com\Tecnick\Pdf\Exception as PdfException;
use Com\Tecnick\Unicode\Bidi;

/**
 * Com\Tecnick\Pdf\Text
 *
 * Text PDF data
 *
 * @since     2002-08-03
 * @category  Library
 * @package   Pdf
 * @author    Nicola Asuni <info@tecnick.com>
 * @copyright 2002-2024 Nicola Asuni - Tecnick.com LTD
 * @license   http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link      https://github.com/tecnickcom/tc-lib-pdf
 *
 * @phpstan-import-type TTextDims from \Com\Tecnick\Pdf\Font\Stack
 *
 * @phpstan-type TextBBox array{
 *          'x': float,
 *          'y': float,
 *          'width': float,
 *          'height': float,
 *      }
 *
 * @phpstan-type TextShadow array{
 *          'xoffset': float,
 *          'yoffset': float,
 *          'opacity': float,
 *          'mode': string,
 *          'color': string,
 *      }
 *
 * @phpstan-type TextLinePos array{
 *          'pos': int,
 *          'chars': int,
 *          'spaces': int,
 *          'septype': string,
 *          'totwidth': float,
 *          'totspacewidth': float,
 *          'words': int,
 *      }
 */
abstract class Text extends \Com\Tecnick\Pdf\Cell
{
    /**
     * Last text bounding box [x, y, width, height] in user units.
     *
     * @var TextBBox
     */
    protected $lasttxtbbox = [
        'x' => 0,
        'y' => 0,
        'width' => 0,
        'height' => 0,
    ];

    /**
     * Add a text column with automatic line, page and region breaks.
     *
     * @param string      $txt         Text string to be processed.
     * @param float       $posx        Abscissa of upper-left corner.
     * @param float       $posy        Ordinate of upper-left corner.
     * @param float       $width       Width.
     * @param float       $offset      Horizontal offset to apply to the line start.
     * @param float       $linespace   Additional space to add between lines.
     * @param float       $strokewidth Stroke width.
     * @param float       $wordspacing Word spacing (use it only when justify == false).
     * @param float       $leading     Leading.
     * @param float       $rise        Text rise.
     * @param bool        $justify     If true justify te text via word spacing.
     * @param bool        $justifylast If true justify the last line.
     * @param bool        $fill        If true fills the text.
     * @param bool        $stroke      If true stroke the text.
     * @param bool        $clip        If true activate clipping mode.
     * @param string      $forcedir    If 'R' forces RTL, if 'L' forces LTR.
     * @param ?TextShadow $shadow      Text shadow parameters.
     */
    public function addTextCol(
        string $txt,
        float $posx = 0,
        float $posy = 0,
        float $width = 0,
        float $offset = 0,
        float $linespace = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $justify = false,
        bool $justifylast = false,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
        string $forcedir = '',
        ?array $shadow = null,
    ): void {
        if ($txt === '') {
            return;
        }

        $ordarr = [];
        $dim = [];
        $this->prepareText($txt, $ordarr, $dim, $forcedir);

        $curfont = $this->font->getCurrentFont();
        $fontascent = $this->toUnit($curfont['ascent']);
        $fontheight = $this->toUnit($curfont['height']);

        $region = $this->page->getRegion();
        $line_posy = ($posy + $region['RY']);

        $region_max_lines  = 1;

        // loop through the regions to fit all available text
        while ($region_max_lines > 0) {
            if ($this->page->isYOutRegion($line_posy, $fontheight)) {
                $this->page->getNextRegion();
                $this->setPageContext();
                $region = $this->page->getRegion();
                $line_posy = $region['RY'];
            }

            $line_posx = ($posx + $region['RX']);

            if (($width == 0) || ($width > $region['RW'])) {
                $width = $region['RW'];
            }

            $region_max_lines = (int)((($region['RH'] - $line_posy) + $linespace) / ($fontheight + $linespace));
            $lines = $this->splitLines($ordarr, $dim, $this->toPoints($width), $this->toPoints($offset));
            $num_lines = count($lines);

            $rlines = $lines;
            if ($num_lines > $region_max_lines) {
                $rlines = array_slice($lines, 0, $region_max_lines);
            }

            $out = $this->outTextLines(
                $ordarr,
                $rlines,
                $line_posx,
                $line_posy,
                $width,
                $offset,
                $fontascent,
                $linespace,
                $strokewidth,
                $wordspacing,
                $leading,
                $rise,
                $justify,
                $justifylast,
                $fill,
                $stroke,
                $clip,
                $shadow,
            );

            $this->page->addContent($out);

            if ($num_lines <= $region_max_lines) {
                return;
            }

            $ordarr = array_slice($ordarr, $lines[$region_max_lines]['pos']);
            $dim = $this->font->getOrdArrDims($ordarr);
            $offset = 0;
            $line_posy = ($this->lasttxtbbox['y'] + $this->lasttxtbbox['height'] + $fontascent + $linespace);
        }
    }

    /**
     * Returns the PDF code to render a text in a given column with automatic line breaks.
     *
     * @param string      $txt         Text string to be processed.
     * @param float       $posx        Abscissa of upper-left corner.
     * @param float       $posy        Ordinate of upper-left corner.
     * @param float       $width       Width.
     * @param float       $offset      Horizontal offset to apply to the line start.
     * @param float       $linespace   Additional space to add between lines.
     * @param float       $strokewidth Stroke width.
     * @param float       $wordspacing Word spacing (use it only when justify == false).
     * @param float       $leading     Leading.
     * @param float       $rise        Text rise.
     * @param bool        $justify     If true justify te text via word spacing.
     * @param bool        $justifylast If true justify the last line.
     * @param bool        $fill        If true fills the text.
     * @param bool        $stroke      If true stroke the text.
     * @param bool        $clip        If true activate clipping mode.
     * @param string      $forcedir    If 'R' forces RTL, if 'L' forces LTR.
     * @param ?TextShadow $shadow      Text shadow parameters.
     *
     * @return string PDF code to render the text.
     */
    public function getTextCol(
        string $txt,
        float $posx = 0,
        float $posy = 0,
        float $width = 0,
        float $offset = 0,
        float $linespace = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $justify = false,
        bool $justifylast = false,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
        string $forcedir = '',
        ?array $shadow = null,
    ): string {
        if ($txt === '') {
            return '';
        }

        $ordarr = [];
        $dim = [];
        $this->prepareText($txt, $ordarr, $dim, $forcedir);

        $curfont = $this->font->getCurrentFont();
        $fontascent = $this->toUnit($curfont['ascent']);

        if ($width == 0) {
            $region = $this->page->getRegion();
            $width = $region['RW'];
        }

        $lines = $this->splitLines($ordarr, $dim, $this->toPoints($width), $this->toPoints($offset));

        return $this->outTextLines(
            $ordarr,
            $lines,
            $posx,
            $posy,
            $width,
            $offset,
            $fontascent,
            $linespace,
            $strokewidth,
            $wordspacing,
            $leading,
            $rise,
            $justify,
            $justifylast,
            $fill,
            $stroke,
            $clip,
            $shadow,
        );
    }

    /**
     * Returns the PDF code to render a contiguous text block with automatic line breaks.
     *
     * @param array<int, int> $ordarr  Array of UTF-8 codepoints (integer values).
     * @param array<int, TextLinePos> $lines    Array of lines metrics.
     * @param float       $posx        Abscissa of upper-left corner.
     * @param float       $posy        Ordinate of upper-left corner.
     * @param float       $width       Width.
     * @param float       $offset      Horizontal offset to apply to the line start.
     * @param float       $fontascent  Font ascent in user units.
     * @param float       $linespace   Additional space to add between lines.
     * @param float       $strokewidth Stroke width.
     * @param float       $wordspacing Word spacing (use it only when justify == false).
     * @param float       $leading     Leading.
     * @param float       $rise        Text rise.
     * @param bool        $justify     If true justify te text via word spacing.
     * @param bool        $justifylast If true justify the last line.
     * @param bool        $fill        If true fills the text.
     * @param bool        $stroke      If true stroke the text.
     * @param bool        $clip        If true activate clipping mode.
     * @param ?TextShadow $shadow      Text shadow parameters.
     *
     * @return string PDF code to render the text.
     */
    protected function outTextLines(
        array $ordarr,
        array $lines,
        float $posx,
        float $posy,
        float $width,
        float $offset,
        float $fontascent,
        float $linespace = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $justify = false,
        bool $justifylast = false,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
        ?array $shadow = null,
    ): string {
        if ($ordarr === [] || $lines === []) {
            return '';
        }

        $num_lines = count($lines);
        $lastline = ($num_lines - 1);

        $line_posx = $posx + $offset;
        $line_posy = $posy + $fontascent;

        $out = '';
        foreach ($lines as $i => $data) {
            $line_ordarr = array_slice($ordarr, $data['pos'], $data['chars']);
            $line_txt = implode('', $this->uniconv->ordArrToChrArr($line_ordarr));
            $line_dim = [
                'chars' => $data['chars'],
                'spaces' => $data['spaces'],
                'totwidth' => $data['totwidth'],
                'totspacewidth' => $data['totspacewidth'],
                'words' => $data['words'],
                'split' => [],
            ];

            $jwidth = 0;
            if ($justify && ($data['septype'] != 'B') && (($i < $lastline) || $justifylast)) {
                $jwidth = ($width - $offset);
            }

            $out .= $this->getOutTextLine(
                $line_txt,
                $line_ordarr,
                $line_dim,
                $line_posx,
                $line_posy,
                $jwidth,
                $strokewidth,
                $wordspacing,
                $leading,
                $rise,
                $fill,
                $stroke,
                $clip,
                $shadow,
            );

            $offset = 0;
            $line_posx = $posx;
            $line_posy = ($this->lasttxtbbox['y'] + $this->lasttxtbbox['height'] + $fontascent + $linespace);
        }

        return $out;
    }

    /**
     * Returns the PDF code to render a single line of text.
     *
     * @param string      $txt         Text string to be processed.
     * @param float       $posx        X position relative to the start of the current line.
     * @param float       $posy        Y position relative to the start of the current line (font baseline).
     * @param float       $width       Desired string width to force justification via word spacing (0 = automatic).
     * @param float       $strokewidth Stroke width.
     * @param float       $wordspacing Word spacing (use it only when width == 0).
     * @param float       $leading     Leading.
     * @param float       $rise        Text rise.
     * @param bool        $fill        If true fills the text.
     * @param bool        $stroke      If true stroke the text.
     * @param bool        $clip        If true activate clipping mode.
     * @param string      $forcedir    If 'R' forces RTL, if 'L' forces LTR.
     * @param ?TextShadow $shadow      Text shadow parameters.
     */
    public function getTextLine(
        string $txt,
        float $posx = 0,
        float $posy = 0,
        float $width = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
        string $forcedir = '',
        ?array $shadow = null,
    ): string {
        if ($txt === '') {
            return '';
        }

        $ordarr = [];
        $dim = [];
        $this->prepareText($txt, $ordarr, $dim, $forcedir);

        return $this->getOutTextLine(
            $txt,
            $ordarr,
            $dim,
            $posx,
            $posy,
            $width,
            $strokewidth,
            $wordspacing,
            $leading,
            $rise,
            $fill,
            $stroke,
            $clip,
            $shadow,
        );
    }

    /**
     * Returns the PDF code to render a single line of text.
     *
     * @param string      $txt         Text string to be processed.
     * @param array<int, int> $ordarr  Array of UTF-8 codepoints (integer values).
     * @param TTextDims   $dim         Array of dimensions
     * @param float       $posx        X position relative to the start of the current line.
     * @param float       $posy        Y position relative to the start of the current line (font baseline).
     * @param float       $width       Desired string width to force justification via word spacing (0 = automatic).
     * @param float       $strokewidth Stroke width.
     * @param float       $wordspacing Word spacing (use it only when width == 0).
     * @param float       $leading     Leading.
     * @param float       $rise        Text rise.
     * @param bool        $fill        If true fills the text.
     * @param bool        $stroke      If true stroke the text.
     * @param bool        $clip        If true activate clipping mode.
     * @param ?TextShadow $shadow      Text shadow parameters.
     */
    protected function getOutTextLine(
        string $txt,
        array $ordarr,
        array $dim,
        float $posx = 0,
        float $posy = 0,
        float $width = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
        ?array $shadow = null,
    ): string {
        if ($txt === '' || $ordarr === [] || $dim === []) {
            return '';
        }

        $out = '';

        if (!empty($shadow)) {
            if ($shadow['xoffset'] < 0) {
                $posx += $shadow['xoffset'];
            }

            if ($shadow['yoffset'] < 0) {
                $posy += $shadow['yoffset'];
            }

            $out .= $this->graph->getStartTransform();
            $out .= $this->color->getPdfColor($shadow['color'], false);
            $out .= $this->graph->getAlpha($shadow['opacity'], $shadow['mode']);
            $out .= $this->outTextLine(
                $txt,
                $ordarr,
                $dim,
                $posx + $shadow['xoffset'],
                $posy + $shadow['yoffset'],
                $width,
                0,
                $wordspacing,
                $leading,
                $rise,
                true,
                false,
                false,
            );
            $out .= $this->graph->getStopTransform();
        }

        return $out . $this->outTextLine(
            $txt,
            $ordarr,
            $dim,
            $posx,
            $posy,
            $width,
            $strokewidth,
            $wordspacing,
            $leading,
            $rise,
            $fill,
            $stroke,
            $clip,
        );
    }

    /**
     * Cleanup the input text, convert it to UTF-8 array and get the dimensions.
     *
     * @param string          $txt      Clean text string to be processed.
     * @param array<int, int> $ordarr   Array of UTF-8 codepoints (integer values).
     * @param TTextDims       $dim      Array of dimensions
     * @param string          $forcedir If 'R' forces RTL, if 'L' forces LTR.
     */
    protected function prepareText(
        string &$txt,
        array &$ordarr,
        array &$dim,
        string $forcedir = '',
    ): void {
        if ($txt === '') {
            return;
        }

        $txt = $this->cleanupText($txt);
        $ordarr = $this->uniconv->strToOrdArr($txt);

        if ($this->isunicode && !$this->font->isCurrentByteFont()) {
            $bidi = new Bidi($txt, null, $ordarr, $forcedir);
            $ordarr = $this->replaceUnicodeChars($bidi->getOrdArray());
        }

        $dim = $this->font->getOrdArrDims($ordarr);
    }

    /**
     * Split the text into lines to fit the specified width.
     *
     * @param array<int, int> $ordarr    Array of UTF-8 codepoints (integer values).
     * @param TTextDims       $dim       Array of dimensions.
     * @param float           $pwidth    Max line width in internal points.
     * @param float           $poffset  Horizontal offset to apply to the line start in internal points.
     *
     * @return array<int, TextLinePos> Array of lines metrics.
     */
    protected function splitLines(
        array $ordarr,
        array $dim,
        float $pwidth,
        float $poffset = 0,
    ): array {
        if (empty($ordarr)) {
            // no lines
            return [];
        }

        $line_width = ($pwidth - $poffset);

        if ($dim['totwidth'] <= $line_width) {
            // single line
            return [[
                'pos' => 0,
                'chars' => $dim['chars'],
                'spaces' => $dim['spaces'],
                'septype' => 'BN',
                'totwidth' => $dim['totwidth'],
                'totspacewidth' => $dim['totspacewidth'],
                'words' => $dim['words'],
            ]];
        }

        $lines = [];
        $posstart = 0;
        $posend = 0;
        $prev_spaces = 0;
        $prev_totwidth = 0;
        $prev_totspacewidth = 0;
        $prev_words = 0;
        $num_lines = count($dim['split']);

        for ($word = 0; $word < $num_lines; $word++) {
            $data = $dim['split'][$word];
            $curwidth = ($data['totwidth'] - $prev_totwidth);
            if (($data['septype'] == 'B') || ($curwidth >= $line_width)) {
                if (($word > 0) && ($curwidth >= $line_width)) {
                    $data = $dim['split'][($word - 1)];
                    --$word;
                }
                $posend = $data['pos'];
                $lines[] = [
                    'pos' => $posstart,
                    'chars' => ($posend - $posstart),
                    'spaces' => ($data['spaces'] - $prev_spaces),
                    'septype' => $data['septype'],
                    'totwidth' => ($data['totwidth'] - $prev_totwidth),
                    'totspacewidth' => ($data['totspacewidth'] - $prev_totspacewidth),
                    'words' => ($word - $prev_words),
                ];

                $posstart = $posend + 1; // skip word separator
                $prev_spaces = $data['spaces'];
                $prev_totwidth = $data['totwidth'];
                $prev_totspacewidth = $data['totspacewidth'];
                $prev_words = $word;
                $line_width = $pwidth;
            }
        }

        if ($posstart < $dim['chars']) {
            $last = $dim['split'][$dim['words'] - 1];
            $lines[] = [
                'pos' => $posstart,
                'chars' => ($dim['chars'] - $posstart),
                'spaces' => ($last['spaces'] - $prev_spaces),
                'septype' => $last['septype'],
                'totwidth' => ($last['totwidth'] - $prev_totwidth),
                'totspacewidth' => ($last['totspacewidth'] - $prev_totspacewidth),
                'words' => ($dim['words'] - $prev_words),
            ];
        }

        return $lines;
    }

    /**
     * Returns the PDF code to render a line of text.
     *
     * @param string          $txt         Clean text string to be processed.
     * @param array<int, int> $ordarr      Array of UTF-8 codepoints (integer values).
     * @param TTextDims       $dim         Array of dimensions.
     * @param float           $posx        X position relative to the start of the current line.
     * @param float           $posy        Y position relative to the start of the current line (font baseline).
     * @param float           $width       Desired string width to force justification via word spacing (0 = automatic).
     * @param float           $strokewidth Stroke width.
     * @param float           $wordspacing Word spacing (use it only when width == 0).
     * @param float           $leading     Leading.
     * @param float           $rise        Text rise.
     * @param bool            $fill        If true fills the text.
     * @param bool            $stroke      If true stroke the text.
     * @param bool            $clip        If true activate clipping mode.
     */
    protected function outTextLine(
        string $txt,
        array $ordarr,
        array $dim,
        float $posx = 0,
        float $posy = 0,
        float $width = 0,
        float $strokewidth = 0,
        float $wordspacing = 0,
        float $leading = 0,
        float $rise = 0,
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false,
    ): string {
        if ($txt === '' || $ordarr === [] || $dim === []) {
            return '';
        }

        $width = $width > 0 ? $width : 0;
        $curfont = $this->font->getCurrentFont();
        $this->lasttxtbbox = [
            'x' => $posx,
            'y' => ($posy - $this->toUnit($curfont['ascent'])),
            'width' => $width,
            'height' => $this->toUnit($curfont['height']),
        ];
        $out = $this->getJustifiedString($txt, $ordarr, $dim, $width);
        $out = $this->getOutTextPosXY($out, $posx, $posy, 'Td');

        $trmode = $this->getTextRenderingMode($fill, $stroke, $clip);
        $out = $this->getOutTextStateOperatorw($out, $this->toPoints($strokewidth));
        $out = $this->getOutTextStateOperatorTr($out, $trmode);
        $out = $this->getOutTextStateOperatorTw($out, $this->toPoints($wordspacing));
        $out = $this->getOutTextStateOperatorTc($out, $curfont['spacing']);
        $out = $this->getOutTextStateOperatorTz($out, $curfont['stretching']);
        $out = $this->getOutTextStateOperatorTL($out, $this->toPoints($leading));
        $out = $this->getOutTextStateOperatorTs($out, $this->toPoints($rise));
        return $this->getOutTextObject($out);
    }

    /**
     * Returns the last text bounding box [llx, lly, urx, ury].
     *
     * @return TextBBox  Array of bounding box values.
     */
    public function getLastTextBBox(): array
    {
        return $this->lasttxtbbox;
    }

    /**
     * Remove special chacters from the text string:
     *     - 'CARRIAGE RETURN' (U+000D)
     *     - 'NO-BREAK SPACE' (U+00A0)
     *     - 'SHY' (U+00AD) SOFT HYPHEN
     *
     * @param string $txt Text string to be processed.
     */
    protected function cleanupText(string $txt): string
    {
        $txt = str_replace("\r", ' ', $txt);
        // replace 'NO-BREAK SPACE' (U+00A0) character with a simple space
        $txt = str_replace($this->uniconv->chr(0x00A0), ' ', $txt);
        // remove 'SHY' (U+00AD) SOFT HYPHEN used for hyphenation
        $txt = str_replace($this->uniconv->chr(0x00AD), '', $txt);
        return $txt;
    }

    /**
     * Returns the string to be used as input for getOutTextShowing().
     *
     * @param string          $txt      Clean text string to be processed.
     * @param array<int, int> $ordarr   Array of UTF-8 codepoints (integer values).
     * @param TTextDims       $dim      Array of dimensions
     * @param float           $width    Desired string width in points (0 = automatic).
     */
    protected function getJustifiedString(
        string $txt,
        array $ordarr,
        array $dim,
        float $width = 0,
    ): string {
        $pwidth = $this->toPoints($width);
        $spacewidth = (($pwidth - $dim['totwidth'] + $dim['totspacewidth']) / ($dim['spaces'] ?: 1));
        if (! $this->isunicode) {
            $txt = $this->encrypt->escapeString($txt);
            $txt = $this->getOutTextShowing($txt, 'Tj');
            if ($pwidth > 0) {
                return $this->getOutTextStateOperatorTw($txt, $this->toPoints($spacewidth));
            }

            $this->lasttxtbbox['width'] = $this->toUnit($dim['totwidth']);
            return $txt;
        }

        if ($this->font->isCurrentByteFont()) {
            $txt = $this->uniconv->latinArrToStr($this->uniconv->uniArrToLatinArr($ordarr));
        } else {
            $unistr = implode('', $this->uniconv->ordArrToChrArr($ordarr));
            $txt = $this->uniconv->toUTF16BE($unistr);
        }

        $txt = $this->encrypt->escapeString($txt);
        if ($pwidth <= 0) {
            $this->lasttxtbbox['width'] = $this->toUnit($dim['totwidth']);
            return $this->getOutTextShowing($txt, 'Tj');
        }

        $fontsize = $this->font->getCurrentFont()['size'] ?: 1;
        $spacewidth = -1000 * $spacewidth / $fontsize;
        $txt = str_replace(chr(0) . chr(32), ') ' . sprintf('%F', $spacewidth) . ' (', $txt);
        return $this->getOutTextShowing($txt, 'TJ');
    }

    /**
     * Get the PDF code for the specified Text Positioning Operator mode.
     *
     * @param string $raw  Raw PDf data to be wrapped by this command.
     * @param float  $posx X position relative to the start of the current line.
     * @param float  $posy Y position relative to the start of the current line.
     * @param string $mode Text state parameter to apply (one of: Td, TD, T*).
     */
    protected function getOutTextPosXY(
        string $raw,
        float $posx = 0,
        float $posy = 0,
        string $mode = 'Td'
    ): string {
        $pntx = $this->toPoints($posx);
        $pnty = $this->toYPoints($posy);
        return match ($mode) {
            'Td' => sprintf('%F %F Td ' . $raw, $pntx, $pnty),
            'TD' => sprintf('%F %F TD ' . $raw, $pntx, $pnty),
            'T*' => 'T* ' . $raw,
            default => '',
        };
    }

    /**
     * Get the text rendering mode.
     *
     * @param bool $fill   If true fills the text.
     * @param bool $stroke If true stroke the text.
     * @param bool $clip   If true activate clipping mode.
     *
     * @return int Text rendering mode as in PDF 32000-1:2008 - 9.3.6 Text Rendering Mode.
     */
    protected function getTextRenderingMode(
        bool $fill = true,
        bool $stroke = false,
        bool $clip = false
    ): int {
        $mode = ((int) $clip << 2) + ((int) $stroke << 1) + ((int) $fill);
        return match ($mode) {
            0 => 3,
            4 => 7,
            default => $mode - 1,
        };
    }

    /**
     * Get the PDF code for the Tc (character spacing) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTc(
        string $raw,
        int|float $value = 0
    ): string {
        if ($value == 0) {
            return $raw;
        }

        return sprintf('%F Tc ' . $raw . ' 0 Tc', $value);
    }

    /**
     * Get the PDF code for the Tw (word spacing) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTw(
        string $raw,
        int|float $value = 0
    ): string {
        if ($value == 0) {
            return $raw;
        }

        return sprintf('%F Tw ' . $raw . ' 0 Tw', $value);
    }

    /**
     * Get the PDF code for the Tz (horizontal scaling) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTz(
        string $raw,
        int|float $value = 0
    ): string {
        if ($value == 1) {
            return $raw;
        }

        return sprintf('%F Tz ' . $raw . ' 100 Tz', $value);
    }

    /**
     * Get the PDF code for the TL (text leading) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTL(
        string $raw,
        int|float $value = 0
    ): string {
        if ($value == 0) {
            return $raw;
        }

        return sprintf('%F TL ' . $raw . ' 0 TL', $value);
    }

    /**
     * Get the PDF code for the Tr (text rendering) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTr(
        string $raw,
        int|float $value = 0
    ): string {
        if (($value < 0) || ($value > 7)) {
            return $raw;
        }

        return sprintf('%d Tr ' . $raw, $value);
    }

    /**
     * Get the PDF code for the Ts (text rise) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorTs(
        string $raw,
        int|float $value = 0
    ): string {
        if ($value == 0) {
            return $raw;
        }

        return sprintf('%F Ts ' . $raw . ' 0 Ts', $value);
    }

    /**
     * Get the PDF code for the w (stroke width) Text State Operator.
     *
     * @param string    $raw   Raw PDf data to be wrapped by this command.
     * @param int|float $value Raw value to apply in internal units.
     */
    protected function getOutTextStateOperatorw(
        string $raw,
        int|float $value = 0
    ): string {
        return sprintf('%F w ' . $raw, ($value > 0 ? $value : 0));
    }

    /**
     * Get the PDF code for the Text Positioning Operator Matrix.
     *
     * @param string                                          $raw    Raw PDf data to be wrapped by this command.
     * @param array{float, float, float, float, float, float} $matrix Text Positioning Operator Matrix.
     */
    protected function getOutTextPosMatrix(
        string $raw,
        array $matrix = [1, 0, 0, 1, 0, 0]
    ): string {
        if (count($matrix) != 6) {
            return '';
        }

        return sprintf(
            '%F %F %F %F %F %F Tm ' . $raw,
            $matrix[0],
            $matrix[1],
            $matrix[2],
            $matrix[3],
            $matrix[4],
            $matrix[5]
        );
    }

    /**
     * Get the PDF code for showing a string.
     *
     * @param string $str  String to show.
     * @param string $mode Text-showing operator to apply (one of: Tj, TJ, ').
     */
    protected function getOutTextShowing(string $str, string $mode = 'Tj'): string
    {
        return match ($mode) {
            'Tj' => '(' . $str . ') Tj',
            'TJ' => '[(' . $str . ')] TJ',
            "'" => '(' . $str . ") '",
            default => '',
        };
    }

    /**
     * Returns a text oject by wrapping the $raw input.
     *
     * @param string $raw Raw PDf data to be wrapped by this command.
     */
    protected function getOutTextObject(string $raw = ''): string
    {
        return 'BT ' . $raw . ' BE' . "\r";
    }

    /**
     * Replace characters for languages like Thai.
     *
     * @param array<int, int> $ordarr Array of UTF-8 codepoints (integer values).
     *
     * @return array<int, int> Array of UTF-8 codepoints (integer values).
     */
    protected function replaceUnicodeChars(array $ordarr): array
    {
        // @TODO
        return $ordarr;
    }

    /**
     * Returns an array of hyphenation patterns.
     *
     * @param string $file TEX file containing hypenation patterns.
     *                     TEX patterns can be downloaded from
     *                     https://www.ctan.org/tex-archive/language/hyph-utf8/tex/generic/hyph-utf8/patterns/tex
     *                     See https://www.ctan.org/tex-archive/language/hyph-utf8/ for more information.
     *
     * @return array<string, string> Array of hyphenation patterns.
     */
    public function loadTexHyphenPatterns(string $file): array
    {
        $pattern = [];
        $data = $this->file->fileGetContents($file);
        // remove comments
        $data = preg_replace('/\%[^\n]*/', '', $data);
        if ($data === null) {
            throw new PdfException('Unable to load hyphenation patterns from file: ' . $file);
        }

        // extract the patterns part
        preg_match('/\\\\patterns\{([^\}]*)\}/i', $data, $matches);
        $data = trim(substr($matches[0], 10, -1));
        // extract each pattern
        $list = preg_split('/[\s]+/', $data);
        if ($list === false) {
            throw new PdfException('Invalid hyphenation patterns from file: ' . $file);
        }

        // map patterns
        $pattern = [];
        foreach ($list as $val) {
            if ($val === '') {
                continue;
            }

            $val = str_replace("'", '\\\'', trim($val));
            $key = preg_replace('/\d+/', '', $val);
            $pattern[$key] = $val;
        }

        return $pattern;
    }
}
