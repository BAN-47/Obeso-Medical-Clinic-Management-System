document.addEventListener("DOMContentLoaded", function () {
    const ta = document.getElementById("soap_input");
    if (!ta) return;

    let step = 0; // 0=HPI,1=O,2=A,3=P,4=done

    ta.value = "HPI: ";

    const labels = ["O: ", "A: ", "P: "];

    ta.addEventListener("keydown", function (e) {
        if (e.key !== "Enter") return;

        e.preventDefault();

        const pos = ta.selectionStart;
        const text = ta.value;

        let insert = "\n";

        // ONLY insert labels until P:
        if (step < labels.length) {
            insert += labels[step];
            step++;
        }

        const newText =
            text.substring(0, pos) +
            insert +
            text.substring(pos);

        ta.value = newText;

        const newPos = pos + insert.length;
        ta.setSelectionRange(newPos, newPos);
    });

    // reset on modal close
    const modal = document.getElementById("newRecordModal");
    if (modal) {
        modal.addEventListener("hidden.bs.modal", function () {
            ta.value = "HPI: ";
            step = 0;
        });
    }
});