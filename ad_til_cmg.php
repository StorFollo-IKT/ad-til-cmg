<?Php
use askommune\EmployeeInfo;
use askommune\EmployeeInfo\exceptions;

function encodeCSV(&$value, $key){ //Funksjon for å lage riktig tegnsett for windows (http://stackoverflow.com/questions/12488954/php-fputcsv-encoding)
	if(!is_string($value))
		$value='';
	else
	{
	    $value = iconv('UTF-8', 'Windows-1252', $value);
		if($value===false)
			die();
	}
}
chdir(dirname(__FILE__));
//Last inn modul for kommunikasjon med AD
require 'adtools/adtools.class.php';
require 'vendor/autoload.php';
$adtools=new adtools('admin');
//Last inn modul for informasjon om ansatte
/*require '../ansattinfo/ansattinfo.class.php';
$ansattinfo=new ansattinfo;*/
//require 'employee-info-stamdata3-extension.class.php';
$ansattinfo_stamdata=new employee_info_stamdata3('/mnt/share/data/Stamdata3_teis_AK.xml');

//Felter som brukes i CMG
$fields_cmg=array('Fornavn','Etternavn','Tlf.nr internt','PBX ID','Mobil','Tittel','Postkassenummer','Arb.adresse','Ekstern nummer','Bruker ID','Meldingssystem 1','Meldings id 1','Meldingssystem 2','Meldings id 2','Arb.gruppe','Organisasjon','Ressursnummer', 'Nøkkelord');
//Knytning mellom felter i AD og CMG. Nøkkel er felt i CMG, verdi er AD
$field_mapping=array('Fornavn'=>'givenName','Etternavn'=>'sn','Mobil'=>'mobile','Tittel'=>'title','Arb.adresse'=>'physicalDeliveryOfficeName','Ekstern nummer'=>'telephoneNumber','Bruker ID'=>'sAMAccountName','Meldings id 1'=>'mail','Meldings id 2'=>'mobile','Arb.gruppe'=>'department','Ressursnummer'=>'employeeID', 'Nøkkelord'=>'otherTelephone');
//Statiske felter som skal være like på alle brukere
$fields_static=array('Meldingssystem 1'=>'E-Mail','Meldingssystem 2'=>'SMS');
//LDAP søk som skal gjøres mot AD. Nøkkel er base DN og verdi er LDAP query
/*$searches=array(
	'OU=Adminnett,DC=as-admin,DC=no'					=>'(&(|(telephoneNumber=*)(&(physicalDeliveryOfficeName=Myrveien 16)(mobile=*)))(objectClass=user))',
	'OU=Teknikk og Miljø,OU=Adminnett,DC=as-admin,DC=no'=>'(&(|(telephoneNumber=*))(objectClass=user))',
	'OU=Driftstjenester,OU=Helse og Sosialtjenester,OU=Adminnett,DC=as-admin,DC=no'=>'(&(|(telephoneNumber=*)(mobile=*))(objectClass=user))',
	'OU=Plan og Utvikling,OU=Adminnett,DC=as-admin,DC=no'=>'(&(|(telephoneNumber=*)(mobile=*))(objectClass=user))',
);*/
$searches=array(
    'OU=Adminnett,DC=as-admin,DC=no'					=>'(&(telephoneNumber=*)(objectClass=user))',
);

/*$name_finds=array('Rådmannens ledergruppe','IKT','etaten','Organisasjons og personalansvar','PPS');
$name_replaces=array('Rådmann','IT','','Organisasjon og personal','Pedagogisk psykologisk senter (PPS)');*/

//$fp=fopen('/mnt/cmg_import/ad_til_cmg.csv','w+');
$fp=fopen(__DIR__.'/ad_til_cmg.csv','w+');
if($fp===false)
	die("Unable to open file\n");

//Gjør om feltnavn i AD til små bokstaver
array_walk($field_mapping,function (&$value,$key){$value=strtolower($value);});

//Utfør søkene
$users=array();
foreach($searches as $base_dn=>$filter)
{
	$result=ldap_search($adtools->ad,$base_dn,$filter,array_values($field_mapping));
	$users_temp=ldap_get_entries($adtools->ad,$result);
	if(is_array($users_temp))
		$users=array_merge($users,$users_temp);
}

unset($users['count']);

//echo sprintf("Found %s users\n",$count=count($users));
$fields_ad=array('givenName','sn','title','physicalDeliveryOfficeName','telephoneNumber','sAMAccountName','mail','mobile','department', 'otherTelephone');

//$fields=array_combine($fields_ad,$fields_cmg);
//$count=$users['count'];

fwrite($fp,implode(';',$fields_cmg)."\r\n");

//Nummerserier på rådhuset
$series[]=array(2000,2599);
$series[]=array(2660,2709);
$series[]=array(2820,2849);
$series[]=array(2900,2999); //Rustad
$series[]=array(300,339);
$series[]=array(4310,4339);
$series[]=array(4400,4499); //Nordby
$series[]=array(4960,4969);
$series[]=array(5020,5039);

//TODO: Fjern $ i nøkkelord
//$value = str_replace('$','',$value);

foreach($users as $user)
{
	//print_r($user);
	if(!empty($user['telephonenumber']))
		$user['telephonenumber']=str_replace(' ','',$user['telephonenumber']);
	if(!empty($user['mobile']))
		$user['mobile']=str_replace(' ','',$user['mobile']);
	if(!empty($user['mail']) && substr($user['mail'][0],0,3)=='xxx') //Hopp over brukere med xxx først i mailadressen
		continue;

	if(!empty($user['employeeid']))
	{
	    try {
            $fields_dynamic['Organisasjon']=$ansattinfo_stamdata->organisation_path($user['employeeid'][0]);
            /*$organisasjon=$ansattinfo_stamdata->organisasjon($ressursnummer=$user['employeeid'][0]);
            if($organisasjon!==false)
            {
                $fields_dynamic['Organisasjon']=$organisasjon;
                $tittel_agresso=(string)$ansattinfo_stamdata->Main_Position($ressursnummer)->PostCodeDescription;
                if(empty($user['title']))
                    $fields_dynamic['Tittel']=$tittel_agresso;
                $fields_dynamic['Tittel agresso']=$tittel_agresso;
            }
            else
            {
                echo 'Feil på bruker '.$user['samaccountname'][0].":\n";
                echo $ansattinfo_stamdata->error."\n";
                $fields_dynamic['Organisasjon']='Ås kommune';
            }*/
        }
        catch (exceptions\DataException|exceptions\NoHitsException $e)
        {
            printf("Error user %s: %s\n", $user['employeeid'][0], $e->getMessage());
            $fields_dynamic['Organisasjon_ny']='';
            $fields_dynamic['Organisasjon']='Ås kommune\\Feil';
        }
        catch (exceptions\EmployeeNotFoundException $e)
        {
            $fields_dynamic['Organisasjon']='Ås kommune\\Sluttet';
        }


		//printf("%s: %s\n",$user['employeeid'],$user['samaccountname']);
		if(substr($user['employeeid'][0],0,1)==4)
			$fields_dynamic['Organisasjon']='Ås kommune\\IKS\\Krise- og incestsenteret i Follo';
		/*elseif($ansattinfo_stamdata->find_employee($user['employeeid'][0])===false)
             $fields_dynamic['Organisasjon']='Ås kommune\\Sluttet';*/

	}
	else
    {
        $ou=$adtools->get_ou($user['dn']);

        $org_field_ou = 'postalCode';
        $result = ldap_read($adtools->ad, $ou, '(objectClass=organizationalUnit)', array($org_field_ou));
        $result = ldap_first_entry($adtools->ad, $result);
        $ou_attributes = ldap_get_attributes($adtools->ad, $result);
        if($user['samaccountname'][0]=='nsv') {
            print_r($user);
            print_r($ou_attributes);
        }
        if(isset($ou_attributes[$org_field_ou]))
        {
            try {
                $org = $ansattinfo_stamdata->organisation_info($ou_attributes[$org_field_ou][0]);
                $fields_dynamic['Organisasjon'] = $ansattinfo_stamdata->organisation_path($org);
            }
            catch (DataException | EmployeeNotFoundException $e)
            {
                $fields_dynamic['Organisasjon']='Ås kommune\\Feil';
            }

        }
        else
            $fields_dynamic['Organisasjon']='Innleide/eksterne';

    }


	/*elseif(!empty($user['department'][0]))
		$fields_static['Organisasjon']='Ås kommune\\'.$user['department'][0];*/
	if(!empty($user['department']) && $user['department'][0]=='Kirkekontoret')
		$fields_dynamic['Organisasjon']='Ås kommune\\IKS\\Kirkekontoret';

	$fields_dynamic['PBX ID']='0'; //PBX ID 0 som standard, endres hvis internt
	if(!empty($user['telephonenumber'])) //Bruker har telefonnummer
	{
		$fields_dynamic['Ekstern nummer']=$user['telephonenumber'][0]; //Enhver verdi i telephone skal være eksternt nummer
		if(substr($user['telephonenumber'][0],0,5)=='64972') //Krisesenteret har internnummer med 3 siffer
			$internnummer=substr($user['telephonenumber'][0],5,3);
		else
			$internnummer=substr($user['telephonenumber'][0],4,4);

		//echo "Henter info for {$user['telephonenumber'][0]}\n";
		if(substr($user['telephonenumber'][0],0,3)=='649')
		{
			foreach($series as $range)
			{
				//Sjekk om nummeret er på MX-ONE på Rådhuset
				if($internnummer>=$range[0] && $internnummer<=$range[1]/* && !empty($ressursnummer)*/)
				{
					$fields_dynamic['Tlf.nr internt']=$internnummer;
					$fields_dynamic['Postkassenummer']=$internnummer;
					$fields_dynamic['PBX ID']='1';
					break;
				}
			}
			$fields_dynamic['Tlf.nr internt']=$internnummer;
		}
		else
		{
			$fields_dynamic['Tlf.nr internt']=$user['telephonenumber'][0];
			if($user['telephonenumber'][0]=='Mobil')
				$fields_dynamic['Tlf.nr internt']='';
			$fields_dynamic['Postkassenummer']='';
			//$fields_static['Organisasjon']='';
			$fields_dynamic['Postkassenummer']='';
		}
		if($user['telephonenumber'][0]=='Mobil') {
		    if(empty($user['mobile'])) {
		        printf("Bruker %s har mobil, men mangler mobilnummer\n", $user['employeeid'][0]);
                //print_r($user);
            }
		    else
                $fields_dynamic['Ekstern nummer'] = $user['mobile'];
        }
		if(!empty($user['othertelephone'])) {
            unset($user['othertelephone']['count']);
            $fields_dynamic['Nøkkelord'] = implode('$', $user['othertelephone']);
        }
	}

	foreach($fields_cmg as $cmg_field) //Loop gjennom feltene i CMG
	{
		if(isset($csv_fields[$cmg_field]))
			continue;
		if(isset($fields_static[$cmg_field])) //Sjekk om feltet skal ha en statisk verdi
			$csv_fields[$cmg_field]=$fields_static[$cmg_field];
		elseif(isset($fields_dynamic[$cmg_field])) //Sjekk om feltet skal ha en dynamisk verdi
			$csv_fields[$cmg_field]=$fields_dynamic[$cmg_field];
		elseif(isset($field_mapping[$cmg_field]) && !empty($user[$field_mapping[$cmg_field]])) //Sjekk om feltet er mappet og om det har en verdi i AD
			$csv_fields[$cmg_field]=$user[$field_mapping[$cmg_field]][0];
		else
		{
			$csv_fields[$cmg_field]='';
		}
	}
    /*if(empty($fields_dynamic['Organisasjon_ny']) && $fields_dynamic['Organisasjon']!='Ås kommune') {
        print_r($fields_cmg);
        print_r($fields_dynamic);
        die();
    }*/

	array_walk($csv_fields,'encodeCSV');
	fwrite($fp,implode(';',$csv_fields)."\r\n");
	unset($csv_fields,$organisasjon,$ressursnummer,$internnummer,$fields_dynamic);
}
fclose($fp);