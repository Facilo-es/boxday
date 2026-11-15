/* BoxDay's public pages. The server does every sum and paints every state: this file only sends choices. */
(function () {
	'use strict';

	function seal(host) {
		if (host.shadowRoot) {
			return host.shadowRoot;
		}
		var tpl = host.querySelector('template');
		var root = host.attachShadow({ mode: 'open' });
		if (tpl) {
			root.appendChild(tpl.content);
			tpl.remove();
		}
		return root;
	}

	function post(path, body, token) {
		var headers = { 'Content-Type': 'application/json' };
		if (token) {
			headers['X-BoxDay-Token'] = token;
		}
		return fetch(window.boxdayApi + path, { method: 'POST', headers: headers, body: JSON.stringify(body) })
			.then(function (r) { return r.json(); });
	}

	function toast(root, text) {
		var el = document.createElement('div');
		el.className = 'toast';
		el.setAttribute('role', 'status');
		el.textContent = text;
		root.querySelector('.boxday').appendChild(el);
		setTimeout(function () { el.remove(); }, 4500);
	}

	function signup(root) {
		var box = root.querySelector('.boxday');
		var holder = box.querySelector('[data-state]');
		if (!holder) {
			return;
		}
		var state = JSON.parse(holder.dataset.state);
		var seq = 0;

		function paint() {
			var mine = ++seq;
			post('quote', state).then(function (res) {
				if (mine !== seq) {
					return;
				}
				if (res.html) {
					box.innerHTML = res.html;
					state = JSON.parse(box.querySelector('[data-state]').dataset.state);
				} else {
					setTimeout(paint, 3000);
				}
			});
		}

		box.addEventListener('click', function (e) {
			var t = e.target.closest('[data-k]');
			if (!t) {
				return;
			}
			var k = t.dataset.k;
			var v = t.dataset.v;
			if ('addon' === k) {
				var n = parseInt(v, 10);
				state.addons = state.addons.indexOf(n) > -1 ? state.addons.filter(function (a) { return a !== n; }) : state.addons.concat(n);
			} else if ('agree' === k) {
				state.agree = !state.agree;
			} else {
				state[k] = v;
			}
			paint();
		});
		box.addEventListener('change', function (e) {
			if (e.target.dataset.field) {
				state[e.target.dataset.field] = e.target.value;
				paint();
			}
		});
		paint();
	}

	function member(root, host) {
		var box = root.querySelector('.boxday');
		var token = host.dataset.token;

		function sheet(id, open) {
			box.querySelectorAll('[data-sheet]').forEach(function (s) {
				s.hidden = !open || s.dataset.sheet !== id;
			});
		}

		function send(path, body) {
			post(path, body, token).then(function (res) {
				if (res.html) {
					box.innerHTML = res.html;
				}
				if (res.error) {
					toast(root, res.error);
				}
				if (res.done) {
					toast(root, res.done);
				}
			});
		}

		box.addEventListener('click', function (e) {
			if (e.target.classList.contains('scrim') || e.target.closest('[data-close]')) {
				sheet('', false);
				return;
			}
			var open = e.target.closest('[data-open]');
			if (open) {
				sheet(open.dataset.open, true);
				return;
			}
			var t = e.target.closest('[data-act]');
			if (!t) {
				return;
			}
			var d = t.dataset;
			var scope = t.closest('.sheet');
			var picked = scope && scope.querySelector('input[type=radio]:checked');
			if ('skip' === d.act || 'unskip' === d.act) {
				send('member/skip', { signup: d.signup, week: d.week, undo: 'unskip' === d.act });
			} else if ('swap' === d.act && picked) {
				send('member/swap', { signup: d.signup, week: d.week, out: d.out, 'in': picked.value });
			} else if ('unswap' === d.act) {
				send('member/swap', { swap: d.swap });
			} else if ('site' === d.act && picked) {
				send('member/site', { signup: d.signup, week: d.week, site: picked.value, scope: d.scope });
			} else if ('away' === d.act) {
				send('member/away', { signup: d.signup, from: scope.querySelector('[name=from]').value, to: scope.querySelector('[name=to]').value });
			} else if ('newlink' === d.act) {
				send('member/newlink', {});
			} else if ('season' === d.act) {
				box.querySelectorAll('.mo.more').forEach(function (m) { m.hidden = !m.hidden; });
			}
		});
		box.addEventListener('submit', function (e) {
			var form = e.target.closest('[data-lost]');
			if (form) {
				e.preventDefault();
				send('link', { email: form.email.value, site_url: form.site_url.value });
			}
		});
	}

	function helper(root, host) {
		var box = root.querySelector('.boxday');
		var token = host.dataset.token;

		function count() {
			var note = box.querySelector('[data-count]');
			var total = parseInt(note.dataset.count, 10);
			var got = box.querySelectorAll('[data-tick]:checked').length;
			note.textContent = note.textContent.replace(/^\d+/, got);
			box.querySelector('.bar i').style.width = (total ? Math.round(100 * got / total) : 0) + '%';
		}

		box.addEventListener('change', function (e) {
			if (e.target.dataset.tick) {
				count();
				post('helper/tick', { signup: e.target.dataset.tick, on: e.target.checked }, token);
			}
		});
		box.addEventListener('input', function (e) {
			if (e.target.hasAttribute('data-find')) {
				var q = e.target.value.toLowerCase();
				box.querySelectorAll('.tick').forEach(function (row) {
					row.hidden = row.textContent.toLowerCase().indexOf(q) < 0;
				});
			}
		});
	}

	// Our stylesheet comes from its file, then goes into each sealed box.
	var sheet = fetch(window.boxdayCss).then(function (r) { return r.text(); });

	function dress(root) {
		var box = root.querySelector('.boxday');
		sheet.then(function (css) {
			if (root.adoptedStyleSheets && window.CSSStyleSheet && CSSStyleSheet.prototype.replaceSync) {
				var s = new CSSStyleSheet();
				s.replaceSync(css);
				root.adoptedStyleSheets = [s];
			} else {
				var el = document.createElement('style');
				el.textContent = css;
				root.insertBefore(el, root.firstChild);
			}
		}).catch(function () {}).then(function () {
			if (box) {
				box.hidden = false;
			}
		});
	}

	document.querySelectorAll('.boxday-root').forEach(function (host) {
		var root = seal(host);
		dress(root);
		var kind = host.dataset.kind;
		if ('signup' === kind) {
			signup(root);
		} else if ('member' === kind) {
			member(root, host);
		} else if ('helper' === kind) {
			helper(root, host);
		}
	});
}());
