/**
 *
 * view_list.html.twig
 *
 */

Window.prototype.removeAllBooks = function removeAllBooks(url){
    if (confirm('Действительно все очистить?')) {
        $.ajax({
            url: url,
            type: "POST",
            dataType: "json",
            async: true,
            success: function (data)
            {
                console.log(data);
                $("#books_list").load(location.href + " #books_list");
            },
            error: function (jqXHR, exception) {
                console.log(exception);
            },
        });
    }
};

Window.prototype.removeBook = function removeBook(url, bookName, bookAuthor, action, params)
{
    if (confirm('Вы на стопроц уверены, что хотите удалить "' + bookName + '" под авторством ' + bookAuthor + '?')) {
        $.ajax({
            url: url,
            type: "POST",
            dataType: "json",
            async: true,
            success: function (data)
            {
                switch(action) {
                    case 'edit':
                        // window.location.href = params;
                        close();
                        break;
                    case 'list':
                        console.log('Книга "' + data + '" успешно уничтожена!');
                        $("div#books_list").load(location.href + " #books_list");
                        break;
                }
            },
            error: function (jqXHR, exception) {
                console.log(exception);
            },
        });
    }
};

Window.prototype.clearCache = function clearCache(url)
{
    $.ajax({
        url: url,
        type: "POST",
        dataType: "json",
        async: true,
        success: function ()
        {
            alert('Кеш успешно очищен!');
            $("div#books_list").load(location.href + " #books_list");
        },
        error: function (jqXHR, exception) {
            console.log(exception);
        },
    });
};

Window.prototype.refreshList = function refreshList(url) {
    $.ajax({
        url: url,
        type: "GET",
        dataType: "json",
        async: true,
        success: function (data)
        {
            let numItems = $('div#book_item').length;
            $("div#books_list").load(location.href + " #books_list");
            alert('Количество новых книг: ' + (data - numItems));
            console.log('Количество новых книг: ' + (data - numItems));
        },
    });
};


/**
 *
 * edit_book.html.twig
 *
 */

Window.prototype.removeBookFile = function removeBookFile(url, type)
{
    if (confirm('Удалить прикрепленный файл навсегда?')) {
        $.ajax({
            url: url,
            type: "POST",
            dataType: "json",
            async: true,
            success: function ()
            {
                console.log('Файл успешно удален!');

                switch(type) {
                    case 'image':
                        $('#cover').remove();
                        break;
                    case 'file':
                        $('#file').remove();
                        break;
                }
            },
            error: function (jqXHR, exception) {
                console.log(exception);
            },
        });
    }
};