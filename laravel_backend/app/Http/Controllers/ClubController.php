<?php

namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Auth;
use App\User;
use App\Club;
use App\Tournament;
use App\Fixtureandresult;
use App\Division;
use App\Setting;
use App\Clubteam;
use App\Team;
use App\Tm;
use JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;




/*
Important info
1. A user can have many clubs 
2. A club can have many league
3. A league can have many tournaments 
4. A tournaments is also a sesion and can have many division
5. A division can have many teams 
6. A team can have two players and a player is a user 



*/


class ClubController extends Controller
{
    // get the club listing with league_count
    public function getClubListing()
    {
        $maintenanceMode=false;
        $msg='Vi uppdaterar PadelMatch-appen. Spelschema och resultat ligger p책 klubbens hemsida';
        $clubs=Club::with(['users' => function($q){
            $q->select('id','profilePic');
        }])->with('leagueCount')
        ->get();

       return response()->json([
            'success' => true,
            'clubs' => $clubs,
            'maintenanceMode' => $maintenanceMode,
            'message' => $msg
        ],200);
    }

    // clubs profile data 
    public function profileData($id){ // user id

       $club=Club::where('user_id', $id)->with(['league', 'users'])->get();
        return response()->json([
            'success' => true,
            'clubData' => $club
        ],200);
      }
  


    // all torunaments (session) with their division of this club
    public function clubTrInfo($id){
        // get all the sessions order by most recent with division since a session can have
        // many division. 
        $tournament=Tournament::where('league_id', $id)
                    ->with('div')
                    ->orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'tournaments' => $tournament
        ],200);
       
    }
    public function singleDivData($id, $lid, $uid){
        $rank=[];
        $div=Fixtureandresult::where('division_id', $id)->orderBy('round', 'asc')
        ->with(['date'=> function($q){
           $q->select('id', 'gameLength','startingDate','startingTime','weekday') ;
        }])
        ->with(['home'=> function($q){
           $q->select('id', 'teamName');
        }])
        ->with(['away'=> function($q){
           $q->select('id', 'teamName');
        }])
        ->with(['home.player1'=> function($q){
           $q->select('id', 'profilePic','firstName','lastName','mobile','phone', 'email');
        }]) 
        ->with(['home.player2'=> function($q){
           $q->select('id', 'profilePic','firstName','lastName', 'mobile','phone', 'email');
        }])
        ->with(['away.player1'=> function($q){
           $q->select('id', 'profilePic','firstName','lastName', 'mobile', 'phone', 'email');
        }]) 
        ->with(['away.player2'=> function($q){
           $q->select('id', 'profilePic','firstName','lastName', 'mobile', 'phone', 'email');
        }])
        ->with(['div'=> function($q){
           $q->select('id', 'divisionName','isPlayOff');
        }])
        ->with(['court'=> function($q){
           $q->select('id', 'courtName');
        }])
        ->with('div.rank')
         ->with(['div.rank.team.player1'=> function($q){
            $q->select('id', 'profilePic','mobile');
         }])
         ->with(['div.rank.team.player2'=> function($q){
            $q->select('id', 'profilePic','mobile');
         }])->get();
        

        // find the history of ranked teams 
        if($div[0]['div']){
            $isRank = $div[0]['div']['rank']; // all ranks are same in all division.
            if(count($isRank)){
                // SORT THE RANKS 
                $inCorrectRank=$div[0]['div']['rank'];
                $drawnTeams=[];
                $lastPickedPoints=-1;
                $size=sizeof($inCorrectRank);
                $correctedRank=[];
                for($i=0; $i<$size; $i++){
                    
                    if($i+1==$size){
                        if($lastPickedPoints==$inCorrectRank[$i]['points']){
                            
                            array_push($drawnTeams, $inCorrectRank[$i]);
                            $lastPickedPoints=$inCorrectRank[$i]['points'];
                        }else{
                            array_push($correctedRank, $inCorrectRank[$i]);
                            
                        }
                    }
                    if($i<$size-1){
                        if($inCorrectRank[$i]['points']==$inCorrectRank[$i+1]['points']){
                            array_push($drawnTeams, $inCorrectRank[$i]);
                            $lastPickedPoints=$inCorrectRank[$i]['points'];
                        }else{
                            if(count($drawnTeams)>0){
                                
                                if($lastPickedPoints==$inCorrectRank[$i]['points']){
                                    array_push($drawnTeams, $inCorrectRank[$i]);
                                    $lastPickedPoints=$inCorrectRank[$i]['points'];
                                }
                                
                                $res = $this->checkInboutMeeting($div,$drawnTeams);
                                $drawnTeams=[];
                                if($res){
                                   // \Log::info("I am in res");
                                    $correctedRank= $this->addRank($res,$correctedRank);
                                }else{
                                   // \Log::info("I am not res");
                                    $correctedRank= $this->addRank($drawnTeams,$correctedRank);
                                }
                                
                                
                            }else{
                                array_push($correctedRank, $inCorrectRank[$i]);
                            }
                           
                            
                            
                        }
                    }
                    if($i+1==$size){
                        
                        if(count($drawnTeams)>0){
                        
                           $res = $this->checkInboutMeeting($div,$drawnTeams);
                            $drawnTeams=[];
                            if($res){
                                //\Log::info("I am in res last res");
                               $correctedRank= $this->addRank($res,$correctedRank);
                            }else{
                                //\Log::info("I am not in res last res");
                                $correctedRank= $this->addRank($drawnTeams,$correctedRank);
                            }
                            
                        }
                    }
                }
                 
                foreach($div as $data){
                    unset($data['div']['rank']);
                    $data['div']['rank'] = $correctedRank; 
                }


                $rank = $div[0]['div']['rank']; // all ranks are same in all division.
                foreach($rank as $r){
                
                    $team_id = $r['team']['id']; 
                    //get the result history of this team 
                    $history = $this->getHistory($div, $team_id);
                    $r['history'] = $history;
                }

            }
        }





       
        // get the games rules using tr id 
        $trId=$div[0]['tournament_id'];
        $gameRules=Setting::where('tournament_id', $trId)->first();
        $upTeamNumbers= $gameRules->numberOfTeamMoveUp;
        $downTeamNumbers= $gameRules->numberOfTeamMoveDown;
        $size=sizeof($rank);
        foreach($rank as $key => $r){
           if($key<$upTeamNumbers){
                $r['status'] = 'up';
            }else if($key>=$size-$downTeamNumbers){
                $r['status'] = 'down';
            }else{
                $r['status'] = 'right';
            }
        }
       
        // re add the rank with history
        foreach($div as $data){
            $data['div']['rank'] = $rank;
        }
        
        $intialDate = strtotime($div[0]['date']['startingDate']);
        
        // ATTACH date time to the division and add the rank possition of teams 
        foreach($div as $d){ 
            // attach date time
            if(!$d['playingDate']){
                if($d['round']!==0){ // if it is not first round, since first round has no changes 
                    $day = 7*$d['round'];
                    $date = strtotime("+$day day", $intialDate); // +7 day
                    $currentWeek= date('Y-m-d', $date); // re assign the current date
                    $d['playingDate'] = $currentWeek;
                }else{
                    $d['playingDate'] = $div[0]['date']['startingDate']; // first date.
                }
            }else{
                $d['date']['startingTime'] = $d['playingTime'];
                $d['date']['weekday'] = $d['weekday'];
               
            }
            

            
            // attach rank possition
            $position = $this->getPosition($d['home']['id'], $d['away']['id'], $rank);
                $d['home']['rankPosition'] = $position['homeTeamPos'];
                $d['home']['status'] = $position['homeTeamPosStatus'];
                $d['away']['rankPosition'] = $position['awayTeamPos'];
                $d['away']['status'] = $position['awayTeamPosStatus'];
           

        }
           
        // find out if this user can edit this result or not 
        $edit= false;
        if($uid>1){
            $canEdit=Tm::where('user_id', $uid)->where('league_id', $lid)->count();
            if($canEdit>0){
                $edit=true;
            }
        }

    
    //return count($correctedRank);
    //return $correctedRank;
   return $data['div']['rank'];
    return response()->json([
        'count' => count($div),
        'divData' => $div, 
        'canEdit' => $edit, 
    ],200);

    }

    public function addRank($item, $arr){
       
        foreach($item as $r){
            array_push($arr,$r);
        }
        return $arr;
    }
   
    
    public function getHistory($data, $team_id){
        $history = [];
        foreach($data as $d){
            if($d['homeTeam'] == $team_id || $d['awayTeam'] == $team_id){
                if($d['homeTeamPoint'] || $d['awayTeamPoint']){ // if there are some results 
                    $txt = "{$d['home']['teamName']} vs {$d['away']['teamName']} {$d['homeTeamPoint']}-{$d['awayTeamPoint']}";
                    $history[]=array(
                        "historyTxt" => $txt,
                        "setOne" => $d['setOne'],
                        "setTwo" => $d['setTwo'],
                        "setThree" => $d['setThree'],
                    );

                    //array_push($history, $txt);
                }
            }
        }
        return $history;
    }
    public function getPosition($homeTeamId, $awayTeamId, $rank){
        $i=1;
        $positionArray=[];
        
        $positionArray['homeTeamPos'] = null; 
        $positionArray['homeTeamPosStatus'] = null; 
        
        $positionArray['awayTeamPos'] = null; 
        $positionArray['awayTeamPosStatus'] = null; 
        $f=0; // if found the teams to brek from the from loop to make it efficient 
        
        foreach($rank as $r){
            if($f==2){
                break;
            }
            
            if($r['team_id']==$homeTeamId){
                
                $positionArray['homeTeamPos'] = $i; 
                $positionArray['homeTeamPosStatus'] = $r['status']; 
                $positionArray['homeName'] = $r['team']['teamName']; 
                $f++;
            }
            if($r['team_id']==$awayTeamId){
               
                $positionArray['awayTeamPos'] = $i; 
                $positionArray['awayTeamPosStatus'] = $r['status'];
                $positionArray['awayName'] = $r['team']['teamName']; 
                $f++;
            }
           
            $i++;
        }
        return $positionArray;
    }
    public function getCurrentUser(Request $request){
        if(!User::checkToken($request)){
            return response()->json([
             'message' => 'Token is required'
            ],422);
        }
         
         $user = JWTAuth::parseToken()->authenticate();
         return $user;
     }

     public function checkInboutMeeting($allMatches,$drawnTeams){
       \Log::info("Bellow team ids has problems");
        foreach($drawnTeams as $t){
            \Log::info($t['team_id']);
        }
        \Log::info("solution started");
        
        \Log::info("====================");

        $sorted=[];
        $lost=[];
        $j=0;
        
        $sortedDrawn=[];
        $sortedLost=[];

        $size=sizeof($drawnTeams);
        $n=($size-1)*($size-1);
        $c=0;
       
        for($m=0; $m<$size; $m++){
            for($j=0; $j<$size; $j++){
                if($drawnTeams[$c]['team_id']!=$drawnTeams[$j]['team_id']){ // escaping the same team compare
                    $currentTeam=$drawnTeams[$c]['team_id'];
					$nextTeam=$drawnTeams[$j]['team_id'];
					$won=false;
                    $isDrawn=false;
                    
                    foreach($allMatches as $match){
                        
                        if( ($currentTeam==$match['homeTeam'] || $currentTeam==$match['awayTeam']) && ($nextTeam==$match['homeTeam'] || $nextTeam==$match['awayTeam']) ){
                            if($currentTeam==$match['homeTeam']){ // current team is home team
                               
								if($match['homeTeamPoint']>$match['awayTeamPoint']){
                                    // current team has won the game being a home team
                                    if(!in_array($drawnTeams[$c], $sorted)){
                                        array_push($sorted, $drawnTeams[$c]);
                                    }
                                    

                                    $won=true;
                                }else{
                                   
                                    if($match['homeTeamPoint']==$match['awayTeamPoint']){
                                        // Its a draw 
                                        if($match['homeTeamPoint'] && $match['awayTeamPoint']){
                                           
                                            $isDrawn=true;
                                        }
                                        
                                        
                                    }
                                   

                                }
                            }else{ // current team is away team
									
                                if($match['homeTeamPoint']<$match['awayTeamPoint']){
                                    // current team has won the game being a home team
                                    if(!in_array($drawnTeams[$c], $sorted)){
                                        array_push($sorted, $drawnTeams[$c]);
                                    }
                                    $won=true;
                                }else{
                                    if($match['homeTeamPoint']==$match['awayTeamPoint']){
                                        // Its a draw 
                                        if($match['homeTeamPoint'] && $match['awayTeamPoint']){
                                           $isDrawn=true;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if(!$won){
                        if(!$isDrawn){
                            // current team lost 
                            array_push($sortedLost, $drawnTeams[$c]);
                        }else{
                           array_push($sortedDrawn, $drawnTeams[$c]);
                        }
                    }

                }
            }


            if($c==$size-1){
                $c=0;
                $item=array_pop($drawnTeams);
                array_unshift($drawnTeams,$item);
                
            }else{
                $c++;
            }

        }
        $totalTeamsNum=sizeof($drawnTeams);
        if(sizeof($sorted)==$totalTeamsNum){
            \Log::info("All are same");
            foreach($sorted as $s){
                \Log::info($s['team_id']);
            }

            return $sorted;
        }
        
       
        foreach($sortedDrawn as $s){
            $found=false;
            foreach($sorted as $w){
               if($w['team_id']==$s['team_id']){
                   $found=true;
                   break;
               }
            }
            if(!$found){
                array_push($sorted, $s);
            }
        }
        foreach($sortedLost as $s){
            $found=false;
            foreach($sorted as $w){
               if($w['team_id']==$s['team_id']){
                   $found=true;
                   break;
               }
            }
            if(!$found){
                array_push($sorted, $s);
            }
           
            
        }

       
        
        foreach($sorted as $s){
            \Log::info($s['team_id']);
        }

        return  $sorted;

        
                
                
    }


}