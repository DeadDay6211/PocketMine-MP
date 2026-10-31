$ErrorActionPreference = 'Stop'
$out = [Console]::Out
$width = -1
$idle = 0
while($true){
	$w = [Console]::WindowWidth
	if($w -ne $width){
		$width = $w
		$out.WriteLine("w $w")
		$out.Flush()
	}
	if([Console]::KeyAvailable){
		$k = [Console]::ReadKey($true)
		$out.WriteLine("k " + [int]$k.Key + " " + [int]$k.KeyChar + " " + [int]$k.Modifiers)
		$out.Flush()
		$idle = 0
	}else{
		Start-Sleep -Milliseconds 15
		$idle++
		if($idle -ge 60){
			$idle = 0
			$out.WriteLine("")
			$out.Flush()
		}
	}
}
