<script>
    // Keeps a short list of the pages seen in this visit so the Back button can tell
    // "there is an earlier page" from "this is where I landed".
    if (! window.aureusTrailReady) {
        window.aureusTrailReady = true;
        window.aureusStack = [];

        const remember = () => {
            const here = location.pathname + location.search;
            const stack = window.aureusStack;

            if (stack.length > 1 && stack[stack.length - 2] === here) {
                stack.pop();
            } else if (stack[stack.length - 1] !== here) {
                stack.push(here);
            }
        };

        document.addEventListener('livewire:navigated', remember);
        remember();
    }
</script>
