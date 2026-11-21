document.addEventListener('DOMContentLoaded', () => {
	const form = document.getElementById('vacations-filter-form');
	const yearSelect = document.getElementById('vacations-year');
	if (form && yearSelect) {
		yearSelect.addEventListener('change', () => form.submit());
	}

	const printButton = document.querySelector('.vacations-print-btn');
	if (printButton) {
		printButton.addEventListener('click', () => {
			const baseUrl =
				window.VACATIONS_PRINT_URL ||
				'/custom/kreaflow/leaves/zsvacations_print.php';
			const selectedYear = yearSelect ? yearSelect.value : '';
			const params = new URLSearchParams({ year: selectedYear || '' });
			const separator = baseUrl.includes('?') ? '&' : '?';
			const targetUrl = baseUrl + separator + params.toString();
			window.open(targetUrl, '_blank', 'noopener');
		});
	}
});
