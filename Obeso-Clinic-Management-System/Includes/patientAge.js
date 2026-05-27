document.getElementById('birthday').addEventListener('change', function () {

    let birthday = new Date(this.value);
    let today = new Date();

    let age = today.getFullYear() - birthday.getFullYear();

    let monthDifference = today.getMonth() - birthday.getMonth();

    if (
        monthDifference < 0 ||
        (monthDifference === 0 && today.getDate() < birthday.getDate())
    ) {
        age--;
    }

    document.getElementById('age').value = age;

});