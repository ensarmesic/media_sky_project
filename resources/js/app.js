// Alpine komponente aplikacije (registruju se prije pokretanja Alpinea koji dolazi s Livewireom).
document.addEventListener('alpine:init', () => {
    Alpine.data('commentBox', (members) => ({
        members, open: false, query: '', start: 0, index: 0, dragging: false,
        get results() {
            const q = this.query.toLowerCase();
            return this.members.filter(m => m.username && (m.username.toLowerCase().includes(q) || m.name.toLowerCase().includes(q))).slice(0, 6);
        },
        detect(event) {
            const el = event.target, pos = el.selectionStart, match = el.value.slice(0, pos).match(/(^|\s)@([\w.\-]*)$/);
            if (match) { this.open = true; this.query = match[2]; this.start = pos - match[2].length; this.index = 0 } else { this.open = false }
        },
        pick(member) {
            const el = this.$refs.textarea, pos = el.selectionStart;
            el.value = el.value.slice(0, this.start) + member.username + ' ' + el.value.slice(pos);
            el.dispatchEvent(new Event('input'));
            const caret = this.start + member.username.length + 1;
            this.open = false;
            this.$nextTick(() => { el.focus(); el.setSelectionRange(caret, caret) });
        },
        keydown(event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') { event.preventDefault(); this.open = false; this.$wire.save(); return }
            if (! this.open || ! this.results.length) return;
            if (event.key === 'ArrowDown') { event.preventDefault(); this.index = (this.index + 1) % this.results.length }
            else if (event.key === 'ArrowUp') { event.preventDefault(); this.index = (this.index - 1 + this.results.length) % this.results.length }
            else if (event.key === 'Enter' || event.key === 'Tab') { event.preventDefault(); this.pick(this.results[this.index]) }
            else if (event.key === 'Escape') { this.open = false }
        },
    }));
});
