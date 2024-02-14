<?php

declare(strict_types = 1);

namespace Drupal\Tests\sermon_audio\Traits;

use Drupal\sermon_audio\FinalTranscriptionGenerator;

/**
 * Stores sample data for transcription tests.
 */
trait SampleTranscriptionDataTrait {

  /** @todo Make into a constant in PHP 8.2. */
  private static string $normalTranscriptionDatum = <<<'EOS'
<transcription>
  <segment start="0.00" end="9.64">Well, good morning, everyone.</segment>
  <segment start="9.64" end="11.44">Welcome back to Acts.</segment>
  <segment start="11.44" end="18.24">In the last class, we started into the full discussion on the book of Acts and covered</segment>
  <segment start="18.24" end="22.64">quite a bit of background material in preparation for going into the text.</segment>
  <segment start="22.64" end="26.60">And there's a little bit more that I want to cover here today before we plunged full</segment>
  <segment start="26.60" end="29.72">stream into the text of the book of Acts.</segment>
  <segment start="29.72" end="34.86">By way of background to help us understand the setting, the cultural context, the historical</segment>
  <segment start="34.86" end="37.26">setting for the book of Acts.</segment>
  <segment start="37.26" end="41.96">And also keep this in mind, it is also for the entire New Testament, the year study of</segment>
  <segment start="41.96" end="44.90">the Gospels and the Epistles.</segment>
  <segment start="44.90" end="49.36">So this information is germane to all of that as well.</segment>
  <segment start="49.36" end="54.68">I do want to mention something briefly about what we call the Greco-Roman world.</segment>
  <segment start="54.68" end="64.60">The Greco-Roman world is kind of a catch-all term for the Greek and Roman world that essentially</segment>
  <segment start="64.60" end="71.60">was the setting for the book of Acts and the New Testament writers and that world.</segment>
  <segment start="71.60" end="77.52">Greco, obviously, I think everybody would know comes from Greece and Roman speaks for</segment>
  <segment start="77.52" end="79.32">itself.</segment>
  <segment start="79.32" end="89.48">And what you have here is a cultural term that describes what was the culture of the</segment>
  <segment start="89.48" end="90.48">setting of the New Testament.</segment>
  <segment start="90.48" end="96.56">It was a world that was largely created by the culture from Greece and largely because</segment>
  <segment start="96.56" end="106.80">of the one person named Alexander the Great who's conquering of the former Persian Empire</segment>
  <segment start="106.80" end="112.32">and all of the lands that we are talking about with the Mediterranean world and Israel,</segment>
  <segment start="112.32" end="118.00">Judea in particular pertains to that.</segment>
  <segment start="118.00" end="122.08">Alexander the Great had a tremendous influence because he spread the Greek language, the</segment>
  <segment start="122.08" end="127.36">Greek culture into all parts of the world that he conquered which included the area</segment>
  <segment start="127.36" end="135.32">of Palestine where the setting for the Bible takes place and created a culture that everybody</segment>
  <segment start="135.32" end="136.84">wanted to be a part of.</segment>
  <segment start="136.84" end="138.60">It was very popular.</segment>
  <segment start="138.60" end="144.96">When the Romans came along and they conquered what was then the Roman Empire or the Greek</segment>
  <segment start="144.96" end="151.04">world and all of this section, this map here shows the Roman world at the time of the writing</segment>
  <segment start="151.04" end="156.00">of the New Testament, the Roman world and Paul's journey is how it's labeled here.</segment>
  <segment start="156.00" end="163.32">But these lighter areas occupy the area of the Roman Empire, not in total.</segment>
  <segment start="163.32" end="166.56">There's a lot more up here including the British Isles but it gives you a view of it</segment>
  <segment start="166.56" end="168.84">at least on the Mediterranean scene.</segment>
  <segment start="168.84" end="174.00">But the Romans adopted much of the Greek culture and influence.</segment>
  <segment start="174.00" end="176.28">The Romans too wanted to be like the Greeks.</segment>
  <segment start="176.28" end="181.88">So they adopted their architecture and their dress and many of their gods and goddesses</segment>
  <segment start="181.88" end="183.76">just kind of co-mingled all together.</segment>
  <segment start="183.76" end="187.04">But it created what we call this Greco-Roman world.</segment>
  <segment start="187.04" end="191.00">And that's a term that you will need to understand.</segment>
  <segment start="191.00" end="194.64">Especially when it comes to just understanding the Roman world.</segment>
  <segment start="194.64" end="202.84">And so the next concept that you need to understand is what happened when the Roman Empire conquered</segment>
  <segment start="202.84" end="209.32">the lands adjacent to what they had and created what we call the Roman Empire.</segment>
  <segment start="209.32" end="213.68">They set up something that then becomes known in history as the Pax Romana.</segment>
  <segment start="213.68" end="217.08">Does anybody know what the Pax Romana stands for?</segment>
  <segment start="217.08" end="218.80">Yes, sir.</segment>
  <segment start="219.12" end="221.12">Is it Roman Peace?</segment>
  <segment start="221.12" end="223.16">Roman Peace or the Peace of Rome?</segment>
  <segment start="223.16" end="231.40">Pax is the word for peace and it's the Peace of Rome.</segment>
  <segment start="231.40" end="238.16">And it was a peace, it was hard won and it was a peace that we gather from the book of</segment>
  <segment start="238.16" end="242.80">Daniel was kind of a peace that was enforced by a rod of iron.</segment>
  <segment start="242.80" end="247.72">Iron is the symbol of the Roman Empire back in the book of Daniel in chapter 2.</segment>
  <segment start="247.72" end="252.04">We'll talk about that as we cover that later on in our study of Daniel.</segment>
  <segment start="252.04" end="254.08">But the Roman Peace was a hard peace.</segment>
  <segment start="254.08" end="257.60">In other words, you did not buck Rome.</segment>
  <segment start="257.60" end="263.84">And if you got out of line, the Roman legions were waiting there to subdue you or reconquer</segment>
  <segment start="263.84" end="268.56">you and make you like it because this is what they wanted.</segment>
  <segment start="268.56" end="271.40">This was the order that they established upon the world.</segment>
  <segment start="271.64" end="279.12">In fact, it's important to realize that the order of life, politically, socially, culturally,</segment>
  <segment start="279.12" end="285.20">economically, and even religiously was something that was very important in the Roman Empire</segment>
  <segment start="285.20" end="287.04">to maintain that order.</segment>
  <segment start="287.04" end="293.32">And they maintained an order for a very long period of time over a diverse area, diversity</segment>
  <segment start="293.32" end="295.48">of peoples, languages, and religions.</segment>
  <segment start="295.48" end="300.48">And it was one of the wonders of the ancient world and unspoken in that sense.</segment>
  <segment start="300.56" end="307.04">But it was a peace that they put upon the nations that they conquered.</segment>
  <segment start="307.04" end="312.24">And we find as the New Testament opens up and the story of the New Testament opens up</segment>
  <segment start="312.24" end="318.36">that the land of Israel and Palestine, a name that actually Rome gave to this region that</segment>
  <segment start="318.36" end="325.36">was formerly the nation of Israel and now basically the people of Judah, they are under</segment>
  <segment start="325.36" end="326.76">the peace of Rome.</segment>
  <segment start="326.76" end="333.88">They don't like it, but they have to live with it as all the other nations and peoples</segment>
  <segment start="333.88" end="339.40">and tribes had to as Rome conquered this area.</segment>
  <segment start="339.40" end="345.92">The Jews have nobody but themselves to blame for that because midway through the first</segment>
  <segment start="345.92" end="352.96">century BC, the Jews actually invited Rome to come in and help them quell a civil war</segment>
  <segment start="352.96" end="356.04">that they had started among themselves.</segment>
  <segment start="356.04" end="358.80">The Jews couldn't live at peace among themselves.</segment>
  <segment start="358.80" end="362.60">And there was this Roman general called Pompey, Pompey the Great, and they said, would you</segment>
  <segment start="362.60" end="364.88">please come down here and be a policeman?</segment>
  <segment start="364.88" end="365.88">And he did.</segment>
  <segment start="365.88" end="367.88">And it was like inviting the fox into the chicken coop.</segment>
  <segment start="367.88" end="369.84">He liked what he found and he just stayed.</segment>
  <segment start="369.84" end="374.08">Rome stayed and they annexed this area.</segment>
  <segment start="374.08" end="379.60">The Jews didn't like it, but when you open the New Testament, that's what you find.</segment>
  <segment start="379.92" end="387.44">And this is important, however, to understand then what Rome imposed upon this world enabled</segment>
  <segment start="387.44" end="396.24">the church to actually do its work and for the gospel to be spread into these lands because</segment>
  <segment start="396.24" end="401.20">Rome not only brought order, they built roads and roads are very important.</segment>
  <segment start="401.20" end="403.08">We all travel our interstate highways today.</segment>
  <segment start="403.08" end="409.00">We appreciate those when they're built and they're well-maintained, rest stops and wide</segment>
  <segment start="409.00" end="412.64">lanes, et cetera, and gets us quickly between cities.</segment>
  <segment start="412.64" end="419.76">The road system that Rome built from connecting all these major areas to Rome and among themselves</segment>
  <segment start="419.76" end="421.76">was a marvelous network.</segment>
  <segment start="421.76" end="423.60">Many of those roads still remain.</segment>
  <segment start="423.60" end="425.14">They were that well-constructed.</segment>
  <segment start="425.14" end="429.20">They're not necessarily used today, but you can find them in various places and actually</segment>
  <segment start="429.20" end="430.56">walk on them.</segment>
  <segment start="430.56" end="432.96">And that's where Paul walked.</segment>
  <segment start="432.96" end="437.84">That's where Peter walked as they took the gospel out.</segment>
  <segment start="437.84" end="441.60">Then mail system was all a part of this as well.</segment>
  <segment start="441.60" end="446.00">So Paul could write a letter and send it to the church at Ephesus from Rome and it would</segment>
  <segment start="446.00" end="447.00">get there.</segment>
  <segment start="447.00" end="451.40">There was a guarantee that it would get there or other communication that people would have</segment>
  <segment start="451.40" end="454.16">for business and other reasons.</segment>
  <segment start="454.16" end="463.00">And so the economic political cultural system was in a general piece that allowed for things</segment>
  <segment start="463.00" end="467.64">to go on and especially the work of the church.</segment>
  <segment start="467.64" end="472.36">There's a scripture in Galatians chapter 4 that we should note and turn to and read</segment>
  <segment start="472.36" end="480.72">at this point that will help us to understand why this Pax Romana is important.</segment>
  <segment start="480.72" end="489.76">Paul is writing to the church in Galatia and while Galatians is a very technical, doctrinal</segment>
  <segment start="489.76" end="497.08">book there, he makes a statement in Galatians 4 and verse 4, Galatians 4, 4.</segment>
  <segment start="497.08" end="502.72">When the fullness of the time had come, God sent forth His Son, born of a woman, born</segment>
  <segment start="502.72" end="503.72">under the law.</segment>
  <segment start="503.72" end="510.08">We're just going to focus on the idea of the fullness of time when God sent forth His</segment>
  <segment start="510.08" end="515.96">Son, born of a woman, which speaks to the birth of Jesus Christ as recorded in Matthew</segment>
  <segment start="515.96" end="523.64">and Mark's gospel in detail, which you'll be studying as you go through the gospels</segment>
  <segment start="523.64" end="526.96">and we know that story in general anyway.</segment>
  <segment start="527.96" end="530.92">Paul calls this the fullness of time.</segment>
  <segment start="530.92" end="534.68">Now what does that mean in connection to what we're talking about here?</segment>
  <segment start="534.68" end="538.76">It means that Jesus was born during the Roman Empire period.</segment>
  <segment start="538.76" end="543.20">He was not born during the Greek Empire period nor the Persian Empire period nor the time</segment>
  <segment start="543.20" end="545.72">of the Babylonian period.</segment>
  <segment start="545.72" end="547.80">And there's reasons for that.</segment>
  <segment start="547.80" end="552.20">We will go into all of them today, but the culture, the civilization and all that I've</segment>
  <segment start="552.20" end="558.72">been explaining had reached in this part of the world such a height and such a stability</segment>
  <segment start="558.72" end="564.96">that this is when God had determined that the birth of His Son would take place in the</segment>
  <segment start="564.96" end="566.84">beginning of the church.</segment>
  <segment start="566.84" end="572.96">Keep in mind that as we will, in a few days, we will be going through Daniel chapter 2</segment>
  <segment start="572.96" end="578.76">and we will read about four empires, Babylon, Persia, Greece, and Rome.</segment>
  <segment start="579.32" end="586.68">And through Daniel, God gives this predictive prophecy of the rise of four great empires.</segment>
  <segment start="586.68" end="592.68">God knew in advance there would be a great empire like Rome and that would be the timing</segment>
  <segment start="592.68" end="596.72">of the sending, if you will, of the Word.</segment>
</transcription>
EOS;

  /**
   * Input transcription XML data to test with.
   *
   * @var string[]
   */
  private static array $inputTranscriptionDatums = [];

  /**
   * Generates and returns transcription XML data with repeated text.
   *
   * Each segment has the same text ($text).
   *
   * @param string $text
   *   Text in each segment.
   * @phpstan-param non-empty-string $text
   * @param float $segmentLength
   *   Length (in seconds) of segments.
   * @param float $semgentSeparation
   *   Separation (in seconds) between segments.
   * @param int $numSegments
   *   Number of segments.
   * @phpstan-param positive-int $numSegments
   */
  private static function generateRepeatedTranscriptionXmlData(string $text, float $segmentLength, float $segmentSeparation, int $numSegments) : string {
    assert($segmentSeparation >= 0);
    assert($segmentLength > 0);

    $xml = '<transcription>';
    $lastEndTime = 0;
    for ($i = 0; $i < $numSegments; $i++) {
      $startTime = $lastEndTime + $segmentSeparation;
      $endTime = $startTime + $segmentLength;
      $xml .= '<segment start="' . $startTime . '" end = "' . $endTime . '">' . $text . '</segment>';
      $lastEndTime = $endTime;
    }
    $xml .= '</transcription>';

    return $xml;
  }

  /**
   * Sets up the sample transcription data.
   */
  private static function setUpTranscriptionDatums() : void {
    self::$inputTranscriptionDatums = [
      'normal.xml' => self::$normalTranscriptionDatum,
      'zero-segment-gaps.xml' => self::generateRepeatedTranscriptionXmlData('My name is Bob.', 2, 0, 1000),
      'empty-or-very-short-segments.xml' => '<transcription><segment start="0" end="1"></segment><segment start="1" end="1"></segment></transcription>',
      'very-short-datum.xml' => '<transcription><segment start="0" end="1">Hi guys!</segment></transcription>',
    ];
  }

}
