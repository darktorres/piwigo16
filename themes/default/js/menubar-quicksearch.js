function pwg_initQuickSearch()
{
	var input = document.getElementById('qsearchInput');
	var form = document.getElementById('quicksearch');
	if (!input || !form)
	{
		return;
	}

	var prompt = pwg_getPageString('Quick search');

	if (input.value === '')
	{
		input.value = prompt;
	}

	input.addEventListener('focus', function() {
		if (input.value === prompt)
		{
			input.value = '';
		}
	});

	input.addEventListener('blur', function() {
		if (input.value === '')
		{
			input.value = prompt;
		}
	});

	form.addEventListener('submit', function(e) {
		if (input.value === '' || input.value === prompt)
		{
			e.preventDefault();
		}
	});
}

pwg_initQuickSearch();
