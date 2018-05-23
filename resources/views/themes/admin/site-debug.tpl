<div class="well well-sm">
	<h1>{$title}</h1>

	<p>Below is a dump of system settings and mysql table status to assist others in debuging problems.
		Items like private 3rd party keys have been removed, however <b>*note*</b> full paths to binaries and web files
		are included - so exercise caution when <a target="null" href="https://newznab.privatepaste.com/">pasting
			online</a>.</p>

	<textarea rows="50" cols="50" style="width:100%;height:500px;">

{$site|print_r}

		{foreach from=$mysql item=data}
			{$data.name} Index Size ({$data.indexsize|filesize}) Data Size ({$data.datasize|filesize})
		{/foreach}

		Totalsize ({$mysqltotalsize|filesize})

</textarea>

	<br/>
</div>

