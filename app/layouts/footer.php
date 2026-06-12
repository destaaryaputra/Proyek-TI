<!-- Footer bersama untuk menutup layout utama halaman. -->
</main>
<footer class="site-footer">
	<div class="container">
		<p>&copy; <?= date('Y') ?> <strong>EduTrack Sekolah</strong>. Hak cipta dilindungi undang-undang.</p>
	</div>
</footer>
<script>
(() => {
	if (document.body.classList.contains('report-page')) {
		return;
	}

	if (!('IntersectionObserver' in window)) {
		return;
	}

	const selectors = [
		'.content-panels > .card',
		'main.container.app-content > .card',
		'.stat',
		'.table-wrap',
		'.section-heading',
		'.action-link',
		'.empty-state',
		'.info-box'
	];

	const elements = Array.from(document.querySelectorAll(selectors.join(',')));
	if (!elements.length) {
		return;
	}

	const assignDirection = (el) => {
		const rect = el.getBoundingClientRect();
		const vh = window.innerHeight || document.documentElement.clientHeight;
		const center = rect.top + (rect.height / 2);

		if (center < vh * 0.33) {
			el.dataset.reveal = 'top';
		} else if (center > vh * 0.67) {
			el.dataset.reveal = 'bottom';
		} else {
			el.dataset.reveal = 'middle';
		}
	};

	elements.forEach((el, i) => {
		el.classList.add('scroll-reveal');
		el.style.setProperty('--reveal-delay', (i % 4) * 55 + 'ms');
		assignDirection(el);
	});

	const observer = new IntersectionObserver((entries) => {
		entries.forEach((entry) => {
			const el = entry.target;
			if (entry.isIntersecting) {
				assignDirection(el);
				el.classList.add('is-visible');
			} else {
				el.classList.remove('is-visible');
			}
		});
	}, {
		root: null,
		threshold: 0.08,
		rootMargin: '0px 0px -2% 0px'
	});

	elements.forEach((el) => observer.observe(el));
})();
</script>
</body>
</html>
