<?php

namespace Foolz\FoolFuuka\Plugins\Bans\Console;

use Foolz\FoolFrame\Model\Context;
use Foolz\FoolFrame\Model\DoctrineConnection;
use Foolz\FoolFuuka\ModeladixCollection;
use Foolz\FoolFrame\Model\Preferences;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Console extends Command
{
    /**
     * @var \Foolz\FoolFrame\Model\Context
     */
    protected $context;

    /**
     * @var DoctrineConnection
     */
    protected $dc;

    /**
     * @var RadixCollection
     */
    protected $radix_coll;

    /**
     * @var Preferences
     */
    protected $preferences;

    public function __construct(Context $context)
    {
        $this->context = $context;
        $this->dc = $context->getService('doctrine');
        $this->radix_coll = $context->getService('foolfuuka.radix_collection');
        $this->preferences = $context->getService('preferences');
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('bans:run')
            ->setDescription('Runs the ban archiver daemon');
    }

    /*public function LevenshteinDistance($s1, $s2) 
    { 
      $sLeft = (strlen($s1) > strlen($s2)) ? $s1 : $s2; 
      $sRight = (strlen($s1) > strlen($s2)) ? $s2 : $s1; 
      $nLeftLength = strlen($sLeft); 
      $nRightLength = strlen($sRight); 
      if ($nLeftLength == 0) 
        return $nRightLength; 
      else if ($nRightLength == 0) 
        return $nLeftLength; 
      else if ($sLeft === $sRight) 
        return 0; 
      else if (($nLeftLength < $nRightLength) && (strpos($sRight, $sLeft) !== FALSE)) 
        return $nRightLength - $nLeftLength; 
      else if (($nRightLength < $nLeftLength) && (strpos($sLeft, $sRight) !== FALSE)) 
        return $nLeftLength - $nRightLength; 
      else { 
        $nsDistance = range(1, $nRightLength + 1); 
        for ($nLeftPos = 1; $nLeftPos <= $nLeftLength; ++$nLeftPos) 
        { 
          $cLeft = $sLeft[$nLeftPos - 1]; 
          $nDiagonal = $nLeftPos - 1; 
          $nsDistance[0] = $nLeftPos; 
          for ($nRightPos = 1; $nRightPos <= $nRightLength; ++$nRightPos) 
          { 
            $cRight = $sRight[$nRightPos - 1]; 
            $nCost = ($cRight == $cLeft) ? 0 : 1; 
            $nNewDiagonal = $nsDistance[$nRightPos]; 
            $nsDistance[$nRightPos] = 
              min($nsDistance[$nRightPos] + 1, 
                  $nsDistance[$nRightPos - 1] + 1, 
                  $nDiagonal + $nCost); 
            $nDiagonal = $nNewDiagonal; 
          } 
        } 
        return $nsDistance[$nRightLength]; 
      } 
    } */


    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->preferences->get('foolfuuka.plugins.bans.get.enabled')) {
            $output->writeln("You need to configure this plugin first in the admin panel.");
            return;
        }

        while (true) {
                $output->writeln("\n* Fetching https://www.4chan.org/bans");

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://www.4chan.org/bans');
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.11; rv:42.0) Gecko/20100101 Firefox/42.0'); // Cloudflare bypass
                $result = curl_exec($ch);
                curl_close($ch);

                preg_match_all("/var postPreviews = (.*?);\n  <\/script>/", $result, $jsmatches);

                if(!isset($jsmatches[1][0])) {
                    die('Error: Could not find JSON. Update regex.');
                }

                $jsdata = json_decode($jsmatches[1][0], true);

                preg_match_all("/<tr>\n<td>\/.*?\/<\/td>\n<td>(.*?)<\/td>\n<td>(.*?)<\/td>\n<td><span class=\"preview-link\" data-pid=\"(.*?)\">(.*?)<\/span><\/td>\n<td>(.*?)<\/td>\n<td class=\"time\" data-utc=\"(.*?)\"><\/td>\n<\/tr>/", $result, $htmlmatches, PREG_SET_ORDER);

                unset($jsmatches);
                unset($result);

                foreach ($htmlmatches as $ban) {
                    $board = $jsdata[$ban[3]]['board'];

                    if(!($radix = $this->radix_coll->getByShortname($board)) || !$this->radix_coll->getByShortname($board)->archive) {
                        $output->writeln("* We don't archive /$board/ skipping...");
                        continue;
                    } else {
                        $board_id = $radix->getValue('id');
                        $output->writeln("* Processing entry for /$board/");
                    }

                    // Find post #
                    if(isset($jsdata[$ban[3]]['tim'])) {
                        // We have an image. Look it up

                        $i = $this->dc->qb()
                            ->select('`num`')
                            ->from($radix->getTable())
                            ->where('media_orig LIKE :filetimestamp')
                            ->setParameter(':filetimestamp', intval($jsdata[$ban[3]]['tim']).'%')
                            ->setMaxResults(1)
                            ->execute()
                            ->fetch();

                        if(isset($i['num'])) {
                            $no = $i['num'];
                        } else {
                            // Post is not here, maybe write it to a new table? 
                            $output->writeln("! Attempting to find the post using the filename failed. We almost certainly do not have this post archived locally. Skipping...");
                            continue;
                        }
                    } else {

                        $i = $this->dc->qb()
                            ->select('`num`,`comment`')
                            ->from($radix->getTable())
                            ->where('timestamp = :posttimestamp')
                            ->orWhere('timestamp = :posttimestamp - 18000')
                            ->orWhere('timestamp = :posttimestamp - 14400')
                            ->setParameter(':posttimestamp', intval($jsdata[$ban[3]]['time']))
                            ->execute()
                            ->fetchAll();

                        $attemptcount = 1;
                        $NumberOfPosts = count($i);

                        $output->writeln("* There are $NumberOfPosts posts matching this timestamp.");

                        // Remove 4chan JSON formatting -> convert to asagi format

                        // Remove HTML Tags
                        $jsdata[$ban[3]]['com'] = str_ireplace(array("&gt;"), ">", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = str_ireplace(array("&#039;"), "'", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = str_ireplace(array("&lt;"), "<", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = str_ireplace(array("&quot;"), "\"", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = str_ireplace(array("&amp;"), "&", $jsdata[$ban[3]]['com']); 

                        // Remove whitespace
                        $jsdata[$ban[3]]['com'] = trim($jsdata[$ban[3]]['com']);

                        /** From ASAGI **/
                        // Admin-Mod-Dev quotelinks 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"capcodeReplies\"><span style=\"font-size: smaller;\"><span style=\"font-weight: bold;\">(?:Administrator|Moderator|Developer) Repl(?:y|ies):<\/span>.*?<\/span><br><\/span>/", "", $jsdata[$ban[3]]['com']); 
                        // Non-public tags
                        $jsdata[$ban[3]]['com'] = preg_replace("/\\[(\/?(banned|moot|spoiler|code))]/", "[$1:lit]", $jsdata[$ban[3]]['com']); 
                        // Comment too long, also EXIF tag toggle
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"abbr\">.*?<\/span>/", "", $jsdata[$ban[3]]['com']); 
                        // EXIF data
                        $jsdata[$ban[3]]['com'] = preg_replace("/<table class=\"exif\"[^>]*>.*?<\/table>/", "", $jsdata[$ban[3]]['com']); 
                        // DRAW data
                        $jsdata[$ban[3]]['com'] = preg_replace("/<br><br><small><b>Oekaki Post<\/b>.*?<\/small>/", "", $jsdata[$ban[3]]['com']); 
                        // Banned/Warned text
                        $jsdata[$ban[3]]['com'] = preg_replace("/<(?:b|strong) style=\"color:\\s*red;\">(.*?)<\/(?:b|strong)>/", "[banned]$1[/banned]", $jsdata[$ban[3]]['com']); 
                        // moot text
                        $jsdata[$ban[3]]['com'] = preg_replace("/<div style=\"padding: 5px;margin-left: \\.5em;border-color: #faa;border: 2px dashed rgba\\(255,0,0,\\.1\\);border-radius: 2px\">(.*?)<\/div>/", "[moot]$1[/moot]", $jsdata[$ban[3]]['com']); 
                        // fortune text
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"fortune\" style=\"color:(.*?)\"><br><br><b>(.*?)<\/b><\/span>/", "\n\n[fortune color=\"$1\"]$2[/fortune]", $jsdata[$ban[3]]['com']); 
                        // bold text
                        $jsdata[$ban[3]]['com'] = preg_replace("/<(?:b|strong)>(.*?)<\/(?:b|strong)>/", "[b]$1[/b]", $jsdata[$ban[3]]['com']); 
                        // code tags
                        $jsdata[$ban[3]]['com'] = preg_replace("/<pre[^>]*>/", "[code]", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<\/pre>/", "[/code]", $jsdata[$ban[3]]['com']); 
                        // math tags
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"math\">(.*?)<\/span>/", "[math]$1[/math]", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<div class=\"math\">(.*?)<\/div>/", "[eqn]$1[/eqn]", $jsdata[$ban[3]]['com']); 
                        // > implying I'm quoting someone
                        $jsdata[$ban[3]]['com'] = preg_replace("/<font class=\"unkfunc\">(.*?)<\/font>/", "$1", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"quote\">(.*?)<\/span>/", "$1", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"(?:[^\"]*)?deadlink\">(.*?)<\/span>/", "$1", $jsdata[$ban[3]]['com']); 
                        // Links
                        $jsdata[$ban[3]]['com'] = preg_replace("/<a[^>]*>(.*?)<\/a>/", "$1", $jsdata[$ban[3]]['com']); 
                        // old spoilers
                        $jsdata[$ban[3]]['com'] = preg_replace("/<span class=\"spoiler\"[^>]*>/", "[spoiler]", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<\/span>/", "[/spoiler]", $jsdata[$ban[3]]['com']); 
                        // new spoilers
                        $jsdata[$ban[3]]['com'] = preg_replace("/<s>/", "[spoiler]", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<\/s>/", "[/spoiler]", $jsdata[$ban[3]]['com']); 
                        // new line/wbr
                        $jsdata[$ban[3]]['com'] = preg_replace("/<br\\s*\/?>/", "\n", $jsdata[$ban[3]]['com']); 
                        $jsdata[$ban[3]]['com'] = preg_replace("/<wbr>/", "", $jsdata[$ban[3]]['com']); 

                        foreach($i as $potentialmatch) {
                            //$diffchars = $this->LevenshteinDistance(trim($potentialmatch['comment']), trim($jsdata[$ban[3]]['com']));
                            //$output->writeln("* { $attemptcount "."/"." $NumberOfPosts} $diffchars characters out of ". strlen($jsdata[$ban[3]]['com'])." did not match."); 
                            if(trim($potentialmatch['comment']) == trim($jsdata[$ban[3]]['com'])) {   // Post matches
                                $no = $potentialmatch['num'];
                                break;
                            }
                            $attemptcount++;
                        }

                        if(!isset($no)) {
                            // Post is not here, maybe write it to a new table? 
                            $output->writeln("! Could not find post. Giving up.");
                        }
                    }

                    if(isset($no)) {
                        if($ban[1] == "Ban") {
                            $type = 1; 
                        } elseif($ban[1] == "Warn") {
                            $type = 0;
                        } else {
                            die('Could not tell Warn or Ban. The script must be updated');
                        }
                        $reason = $ban[5];
                        $banlength = $ban[2];
                        $bantime = $ban[6];

                        $j = $this->dc->qb()
                            ->select('COUNT(`id`) as count')
                            ->from($this->dc->p('plugin_fu_ban_logging'))
                            ->where('board_id = :board_id')
                            ->andWhere('no = :no')
                            ->setParameter(':board_id', $board_id)
                            ->setParameter(':no', $no)
                            ->execute()
                            ->fetch();
                        if (!$j['count']) {
                            $this->dc->getConnection()
                                ->insert($this->dc->p('plugin_fu_ban_logging'), [
                                    'board_id' => $board_id,
                                    'no' => $no,
                                    'type' => $type,
                                    'reason' => $reason,
                                    'banlength' => $banlength,
                                    'bantime' => $bantime
                                ]);
                            $output->writeln("* Added /$board/ $no"); 
                        }
                    }
                    
                    unset($radix);
                    unset($board_id);
                    unset($no);
                    unset($type);
                    unset($reason);
                    unset($banlength);
                    unset($bantime);
                }

            $sleep = $this->preferences->get('foolfuuka.plugins.bans.get.sleep');
            $output->writeln("\n* Sleeping for $sleep minutes");
            sleep($sleep * 60);
        }
    }
}