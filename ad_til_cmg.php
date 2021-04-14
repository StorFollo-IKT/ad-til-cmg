<?Php

use storfollo\adtools;
use storfollo\EmployeeInfo\exceptions;
use storfollo\EmployeeInfo\employee_info_stamdata3;

function encodeCSV(&$value, $key){ //Funksjon for å lage riktig tegnsett for windows (http://stackoverflow.com/questions/12488954/php-fputcsv-encoding)
    $value_old = $value;
	if(!is_string($value))
		$value='';
	else
	{
	    $value = @iconv('UTF-8', 'Windows-1252', $value);
		if($value===false)
        {
            $error = error_get_last();
            throw new RuntimeException(sprintf('Error converting "%s" key "%s" to Windows-1252: %s', $value_old, $key, $error['message']));
        }
	}
}
chdir(dirname(__FILE__));
//Last inn modul for kommunikasjon med AD
require 'vendor/autoload.php';
$adtools=new adtools\adtools('admin');
//Last inn modul for informasjon om ansatte
$ansattinfo_stamdata=new employee_info_stamdata3('/mnt/share/data/Stamdata3_teis_AK.xml');

//Felter som brukes i CMG
$fields_cmg=array('Fornavn','Etternavn','Tlf.nr internt','PBX ID','Mobil','Tittel','Postkassenummer','Arb.adresse','Ekstern nummer','Bruker ID','Meldingssystem 1','Meldings id 1','Meldingssystem 2','Meldings id 2','Arb.gruppe','Organisasjon','Ressursnummer', 'Nøkkelord');
//Knytning mellom felter i AD og CMG. Nøkkel er felt i CMG, verdi er AD
$field_mapping=array('Fornavn'=>'givenName','Etternavn'=>'sn','Mobil'=>'mobile','Tittel'=>'title','Arb.adresse'=>'physicalDeliveryOfficeName','Ekstern nummer'=>'telephoneNumber','Bruker ID'=>'sAMAccountName','Meldings id 1'=>'mail','Meldings id 2'=>'mobile','Arb.gruppe'=>'department','Ressursnummer'=>'employeeID', 'Nøkkelord'=>'otherTelephone');
//Statiske felter som skal være like på alle brukere
$fields_static=array('Meldingssystem 1'=>'E-Mail','Meldingssystem 2'=>'SMS');
//LDAP søk som skal gjøres mot AD. Nøkkel er base DN og verdi er LDAP query
$searches=array(
    'OU=Adminnett,DC=as-admin,DC=no'					=>'(&(telephoneNumber=*)(objectClass=user))',
);

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

$fields_ad=array('givenName','sn','title','physicalDeliveryOfficeName','telephoneNumber','sAMAccountName','mail','mobile','department', 'otherTelephone');

fwrite($fp,implode(';',$fields_cmg)."\r\n");

$config = require 'config.php';

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
        }
        catch (exceptions\DataException|exceptions\NoHitsException|InvalidArgumentException $e)
        {
            printf("Error user %s: %s\n", $user['employeeid'][0], $e->getMessage());
            $fields_dynamic['Organisasjon_ny']='';
            $fields_dynamic['Organisasjon']='Ås kommune\\Feil';
        }
        catch (exceptions\EmployeeNotFoundException $e)
        {
            $fields_dynamic['Organisasjon']='Ås kommune\\Sluttet';
        }

		if(substr($user['employeeid'][0],0,1)==4)
			$fields_dynamic['Organisasjon']='Ås kommune\\IKS\\Krise- og incestsenteret i Follo';
	}
	else
    {
        $ou=adtools\adtools_utils::ou($user['dn']);

        $org_field_ou = 'postalCode';
        $result = ldap_read($adtools->ad, $ou, '(objectClass=organizationalUnit)', array($org_field_ou));
        $result = ldap_first_entry($adtools->ad, $result);
        $ou_attributes = ldap_get_attributes($adtools->ad, $result);

        if(isset($ou_attributes[$org_field_ou]))
        {
            try {
                $org = $ansattinfo_stamdata->organisation_info($ou_attributes[$org_field_ou][0]);
                $fields_dynamic['Organisasjon'] = $ansattinfo_stamdata->organisation_path($org);
            }
            catch (exceptions\DataException | exceptions\EmployeeNotFoundException | exceptions\NoHitsException $e)
            {
                $fields_dynamic['Organisasjon']='Ås kommune\\Feil';
            }
        }
        else
            $fields_dynamic['Organisasjon']='Innleide/eksterne';

    }

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
			foreach($config['series'] as $range)
			{
				//Sjekk om nummeret er på MX-ONE på Rådhuset
				if($internnummer>=$range[0] && $internnummer<=$range[1])
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
    try
    {
        array_walk($csv_fields, 'encodeCSV');
    }
	catch (RuntimeException $e)
    {
        echo $e->getMessage()."\n";
        echo $e->getTraceAsString()."\n";
        print_r($user);
        continue;
    }
	fwrite($fp,implode(';',$csv_fields)."\r\n");
	unset($csv_fields,$organisasjon,$ressursnummer,$internnummer,$fields_dynamic);
}
fclose($fp);