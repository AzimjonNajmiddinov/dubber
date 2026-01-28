<!DOCTYPE html>
<html>
<head>
    <title>Movie Dubber</title>
</head>
<body>

<h2>🎬 Video yuklash</h2>

@if(session('success'))
    <p style="color:green">{{ session('success') }}</p>
@endif

<form action="/upload" method="POST" enctype="multipart/form-data">
    @csrf

    <input type="file" name="video" required>
    <br><br>

    <label>Tarjima tili:</label>
    <select name="target_language">
        <option value="uz">O‘zbek</option>
        <option value="ru">Rus</option>
        <option value="en">English</option>
    </select>

    <br><br>
    <button type="submit">Yuklash</button>
</form>

</body>
</html>
