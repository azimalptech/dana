/// Turkmen, Russian and English UI strings.
///
/// FR-13.22: English is the third interface language. The redesign's
/// frames are the English copy, verbatim — so the `en` column is the
/// design and the other two translate it.
/// A plain map keeps the build free of code generation — one less thing
/// to fail on a first `flutter run`.
///
/// TRANSLATION NOTE: reviewed for grammar but written by a non-native
/// speaker. Worth a pass from a native speaker before release.
class L {
  L(this.lang);

  final String lang; // 'tk' | 'ru' | 'en'

  static const supported = ['tk', 'ru', 'en'];

  static const _strings = <String, Map<String, String>>{
    'welcome_back': {
      'tk': 'Hoş geldiňiz!',
      'ru': 'С возвращением!',
      'en': 'Welcome Back!',
    },
    'login_subtitle': {
      'tk': 'Hasabyňyza giriň',
      'ru': 'Войдите в свой аккаунт',
      'en': 'Login to your account',
    },
    'phone_number': {
      'tk': 'Telefon belgisi',
      'ru': 'Номер телефона',
      'en': 'Phone number',
    },
    'password': {'tk': 'Parol', 'ru': 'Пароль', 'en': 'Password'},
    'log_in': {'tk': 'Giriş', 'ru': 'Войти', 'en': 'Log In'},
    'choose_language': {
      'tk': 'Dili saýlaň',
      'ru': 'Выберите язык',
      'en': 'Choose your language',
    },
    'hello': {'tk': 'Salam', 'ru': 'Привет', 'en': 'Hello'},
    'keep_learning': {
      'tk': 'Okuw ýoluňyzy dowam edeliň.',
      'ru': 'Продолжим ваше обучение.',
      'en': "Let's continue your learning journey.",
    },
    'course_overview': {
      'tk': 'Okuw maglumaty',
      'ru': 'О курсе',
      'en': 'Course overview',
    },
    // Home's "13/36 Units completed". A whole pattern per language: the
    // Russian noun inflects with the count and Turkmen has no article,
    // so parts cannot be concatenated.
    'units_completed_line': {
      'tk': '{n}/{m} bölüm tamamlandy',
      'ru': '{n}/{m} {w} завершено',
      'en': '{n}/{m} Units completed',
    },
    'overall': {'tk': 'Umumy', 'ru': 'Общий', 'en': 'Overall'},
    'unit': {'tk': 'Bölüm', 'ru': 'Юнит', 'en': 'Unit'},
    'exercise': {'tk': 'Maşk', 'ru': 'Упражнение', 'en': 'Exercise'},
    'exercises': {'tk': 'Maşklar', 'ru': 'Упражнения', 'en': 'Exercises'},
    'vocabulary': {'tk': 'Sözlük', 'ru': 'Словарь', 'en': 'Vocabulary'},
    'grammar': {'tk': 'Grammatika', 'ru': 'Грамматика', 'en': 'Grammar'},
    'listening': {'tk': 'Diňlemek', 'ru': 'Аудирование', 'en': 'Listening'},
    'leaderboard': {'tk': 'Reýting', 'ru': 'Рейтинг', 'en': 'Ranking'},
    'profile': {'tk': 'Profil', 'ru': 'Профиль', 'en': 'Profile'},
    'home': {'tk': 'Baş sahypa', 'ru': 'Главная', 'en': 'Home'},
    'points': {'tk': 'bal', 'ru': 'балл|балла|баллов', 'en': 'point|points'},
    'all_words': {'tk': 'Ähli sözler', 'ru': 'Все слова', 'en': 'All Words'},
    'bookmarked': {'tk': 'Bellenen', 'ru': 'Избранное', 'en': 'Bookmarked'},
    'continue_': {'tk': 'Dowam et', 'ru': 'Продолжить', 'en': 'Continue'},
    'check': {'tk': 'Barla', 'ru': 'Проверить', 'en': 'Check'},
    'submit_and_check': {
      'tk': 'Ugrat we barla',
      'ru': 'Отправить и проверить',
      'en': 'Submit and check',
    },
    'fill_in_gaps': {
      'tk': 'Boşluklary dolduryň',
      'ru': 'Заполните пропуски',
      'en': 'Fill in the blanks',
    },
    'word_pool': {'tk': 'Sözler', 'ru': 'Набор слов', 'en': 'Word pool'},
    'choose_right_answer': {
      'tk': 'Dogry jogaby saýlaň',
      'ru': 'Выберите правильный ответ',
      'en': 'Choose the right answer',
    },
    'connect_pairs': {
      'tk': 'Jübütleri birleşdiriň',
      'ru': 'Соедините пары',
      'en': 'Connect the pairs',
    },
    'put_in_order': {
      'tk': 'Sözleri tertipleşdiriň',
      'ru': 'Расставьте слова по порядку',
      'en': 'Put the words in order',
    },
    'correct': {'tk': 'Dogry!', 'ru': 'Правильно!', 'en': 'Correct!'},
    'incorrect': {'tk': 'Nädogry', 'ru': 'Неправильно', 'en': 'Incorrect'},
    'finish': {'tk': 'Tamamla', 'ru': 'Завершить', 'en': 'Finish'},
    'completed': {'tk': 'Tamamlandy', 'ru': 'Завершено', 'en': 'Completed'},
    'not_started': {
      'tk': 'Başlanmadyk',
      'ru': 'Не начато',
      'en': 'Not started',
    },
    'in_progress': {
      'tk': 'Dowam edýär',
      'ru': 'В процессе',
      'en': 'In progress',
    },
    'you_earned': {
      'tk': 'Siz gazandyňyz',
      'ru': 'Вы заработали',
      'en': 'You earned',
    },
    'streak': {'tk': 'Yzygiderli günler', 'ru': 'Дней подряд', 'en': 'Daily streak'},
    'study_time': {'tk': 'Okuw wagty', 'ru': 'Время обучения', 'en': 'Study time'},
    'log_out': {'tk': 'Çykyş', 'ru': 'Выйти', 'en': 'Logout'},
    // Figma `profile-language-1`: the confirm dialog under the Logout row.
    'logout_body': {
      'tk': 'Hasapdan çykmak isleýärsiňizmi? Netijeleriňizi görmek üçin '
          'täzeden girmeli bolarsyňyz.',
      'ru': 'Вы действительно хотите выйти? Чтобы увидеть свой прогресс, '
          'нужно будет войти снова.',
      'en': 'Do you really want to logout? You need to sign in again to '
          'see your progress.',
    },
    'delete_account': {
      'tk': 'Hasaby pozmak',
      'ru': 'Удалить аккаунт',
      'en': 'Delete Account',
    },
    'delete': {'tk': 'Poz', 'ru': 'Удалить', 'en': 'Delete'},
    // Figma `profile-language`: the Delete Account confirm dialog.
    'delete_account_body': {
      'tk': 'Hasabyňyzy hakykatdan-da pozmak isleýärsiňizmi? '
          'Netijeleriňizi dikeldip bolmaz.',
      'ru': 'Вы действительно хотите удалить аккаунт? '
          'Восстановить прогресс будет невозможно.',
      'en': "Do you really want to delete your account? "
          "You can't restore your data progress.",
    },
    // Wordlist v2's Type column, shown as the word card's amber chip.
    'word_type_word': {'tk': 'Söz', 'ru': 'Слово', 'en': 'Word'},
    'word_type_phrase': {'tk': 'Söz düzümi', 'ru': 'Фраза', 'en': 'Phrase'},
    'bookmark_added': {
      'tk': 'Bellenenlere goşuldy',
      'ru': 'Добавлено в избранное',
      'en': 'Bookmarked',
    },
    'bookmark_removed': {
      'tk': 'Bellenenlerden aýryldy',
      'ru': 'Удалено из избранного',
      'en': 'Removed from bookmarks',
    },
    'interface_language': {
      'tk': 'Interfeýs dili',
      'ru': 'Язык интерфейса',
      'en': 'Interface Language',
    },
    'language': {'tk': 'Dil', 'ru': 'Язык', 'en': 'Language'},
    // The language modal translates the language names like everything
    // else — the frame shows the English set ("English / Turkmen /
    // Russian"), which is the en column.
    'language_en': {'tk': 'Iňlis dili', 'ru': 'Английский', 'en': 'English'},
    'language_tk': {'tk': 'Türkmen dili', 'ru': 'Туркменский', 'en': 'Turkmen'},
    'language_ru': {'tk': 'Rus dili', 'ru': 'Русский', 'en': 'Russian'},
    'no_content': {
      'tk': 'Häzirlikçe maglumat ýok',
      'ru': 'Пока нет материалов',
      'en': 'No content yet',
    },
    // Pre-redesign copy (FR-13.3 removed unlocking); kept for the
    // teacher screens, which still describe the old flow.
    'locked_hint': {
      'tk': 'Mugallym sapagy başlanda açylar',
      'ru': 'Откроется, когда преподаватель начнёт урок',
      'en': 'Opens when the teacher starts the lesson',
    },
    'connection_error': {
      'tk': 'Birikme ýok. Internetiňizi barlaň.',
      'ru': 'Нет соединения. Проверьте интернет.',
      'en': 'No connection. Check your internet.',
    },
    'match_pairs_hint': {
      'tk': 'Jübütleri birleşdiriň',
      'ru': 'Соедините пары',
      'en': 'Connect the pairs',
    },
    'tap_to_fill': {'tk': 'Sözi saýlaň', 'ru': 'Выберите слово', 'en': 'Choose a word'},
    // The audio-stem tile caption (Test-audio frame, rendered uppercase).
    'tap_to_listen': {
      'tk': 'Diňlemek üçin basyň',
      'ru': 'Нажмите, чтобы прослушать',
      'en': 'Tap to listen',
    },
    'teacher_classrooms': {
      'tk': 'Synplarym',
      'ru': 'Мои классы',
      'en': 'My classes',
    },
    // Teacher home (design `home-screen`): the header greeting over the
    // name, and the right-hand "4 CLASSES" caption.
    'hello_teacher': {
      'tk': 'Salam, mugallym!',
      'ru': 'Здравствуйте, учитель!',
      'en': 'Hello Teacher!',
    },
    'classes_count': {
      'tk': 'synp',
      'ru': 'класс|класса|классов',
      'en': 'class|classes',
    },
    // Teacher unit view (design `unit-vocabulary-screen-3`).
    'unit_overall': {
      'tk': 'Bölümiň netijesi',
      'ru': 'Итог по юниту',
      'en': 'Unit overall',
    },
    'students': {'tk': 'Okuwçylar', 'ru': 'Ученики', 'en': 'Students'},
    'start_teaching': {
      'tk': 'Sapagy başla',
      'ru': 'Начать урок',
      'en': 'Start teaching',
    },
    'opened': {'tk': 'Açyk', 'ru': 'Открыт', 'en': 'Open'},
    // FR-1.4 (2026-08-07): enrolment belongs to the centre admin now, so
    // the teacher app explains instead of offering a button.
    'students_added_by_admin': {
      'tk': 'Okuwçylary merkeziň administratory goşýar. Oňa ýüz tutuň.',
      'ru': 'Учеников добавляет администратор центра. Обратитесь к нему.',
      'en': 'Students are added by the centre admin. Please contact them.',
    },
    'progress': {'tk': 'Öňegidiş', 'ru': 'Прогресс', 'en': 'Progress'},
    // A set contains questions, not exercises — the set itself is the
    // exercise. Reusing 'exercise' here read as "12 exercises".
    'questions': {
      'tk': 'sorag',
      'ru': 'вопрос|вопроса|вопросов',
      'en': 'question|questions',
    },
    'main': {'tk': 'Baş sahypa', 'ru': 'Главная', 'en': 'Main'},
    'ranking': {'tk': 'Reýting', 'ru': 'Рейтинг', 'en': 'Ranking'},
    // Teacher's Student Detail screen (design `student-detail`).
    'student_detail': {
      'tk': 'Okuwçy barada',
      'ru': 'Об ученике',
      'en': 'Student Detail',
    },
    'overall_progress': {
      'tk': 'Umumy ösüş',
      'ru': 'Общий прогресс',
      'en': 'Overall Progress',
    },
    'unit_progress': {
      'tk': 'Bölümler boýunça ösüş',
      'ru': 'Прогресс по юнитам',
      'en': 'Unit Progress',
    },
    'total_active': {
      'tk': 'Jemi işjeň wagt',
      'ru': 'Всего активности',
      'en': 'Total Active',
    },
    'dictionary': {'tk': 'Sözlük', 'ru': 'Словарь', 'en': 'Vocabulary'},
    'search_grammar': {
      'tk': 'Grammatika gözle...',
      'ru': 'Поиск по грамматике...',
      'en': 'Search grammar topics...',
    },
    'search_words': {
      'tk': 'Söz gözle',
      'ru': 'Поиск слова',
      'en': 'Search words',
    },
    // Word order differs between the languages — Turkmen puts the count
    // before its noun and marks the source with a suffix, Russian needs
    // a preposition. Concatenating parts cannot produce all three, so
    // these are whole patterns with placeholders.
    'summary_grammar': {
      'tk': '{n} mowzuk · {u} bölümden',
      'ru': '{n} {w} · из {u} {v}',
      'en': '{n} {w} · from {u} {v}',
    },
    'view_all': {'tk': 'Ählisi', 'ru': 'Все', 'en': 'View All'},
    // Bare nouns, used where the count is rendered separately. Distinct
    // from the summary_* patterns, which carry their own word order.
    // Russian forms are one|few|many, English one|many — read them
    // through plural(), never t(), or the bar shows up on screen.
    'words': {'tk': 'söz', 'ru': 'слово|слова|слов', 'en': 'word|words'},
    'topics': {'tk': 'mowzuk', 'ru': 'тема|темы|тем', 'en': 'topic|topics'},
    // These two only ever follow the preposition «из», which governs the
    // genitive — "из 1 юнита", "из 2 юнитов". The nominative forms the
    // other counted nouns use would read "из 1 юнит".
    'units_noun': {
      'tk': 'bölüm',
      'ru': 'юнита|юнитов|юнитов',
      'en': 'unit|units',
    },
    'sections_noun': {
      'tk': 'bölüm',
      'ru': 'раздела|разделов|разделов',
      'en': 'section|sections',
    },
    'exercises_completed': {
      'tk': 'maşk tamamlandy',
      'ru': 'упражнений завершено',
      'en': 'exercises completed',
    },
    'summary_words': {
      'tk': '{n} söz · {u} bölümden',
      'ru': '{n} {w} · из {u} {v}',
      'en': '{n} {w} · from {u} {v}',
    },
    'nothing_found': {
      'tk': 'Hiç zat tapylmady',
      'ru': 'Ничего не найдено',
      'en': 'Nothing found',
    },
    'no_bookmarks': {
      'tk': 'Bellenen söz ýok',
      'ru': 'Нет избранных слов',
      'en': 'No bookmarked words',
    },
    'examples': {'tk': 'Mysallar', 'ru': 'Примеры', 'en': 'Examples'},
    'pronunciation': {
      'tk': 'Aýdylyşy',
      'ru': 'Произношение',
      'en': 'Pronunciation',
    },
    'example': {'tk': 'Mysal', 'ru': 'Пример', 'en': 'Example'},
    // The word modal's definition block (Figma `vocabulary-screen-modal`).
    'meaning': {'tk': 'Manysy', 'ru': 'Значение', 'en': 'Meaning'},
    'full_name': {'tk': 'Doly ady', 'ru': 'Полное имя', 'en': 'Full name'},
    'phone_hint': {
      'tk': '+993 we 8 sana. Meselem: +99365123456',
      'ru': '+993 и 8 цифр. Например: +99365123456',
      'en': '+993 and 8 digits. For example: +99365123456',
    },
    'password_hint_student': {
      'tk': 'Azyndan 4 belgi',
      'ru': 'Не менее 4 символов',
      'en': 'At least 4 characters',
    },
    'save': {'tk': 'Ýatda sakla', 'ru': 'Сохранить', 'en': 'Save'},
    'cancel': {'tk': 'Ýatyr', 'ru': 'Отмена', 'en': 'Cancel'},
    'notifications': {
      'tk': 'Habarnamalar',
      'ru': 'Уведомления',
      'en': 'Notifications',
    },
    'no_notifications': {
      'tk': 'Habarnama ýok',
      'ru': 'Нет уведомлений',
      'en': 'No notifications',
    },
    'offline_copy': {
      'tk': 'Internetsiz görkezilýär',
      'ru': 'Показано без интернета',
      'en': 'Shown offline',
    },
    // Figma `leaderboard-screen`: the info card above the podium. The
    // averaged score rule is FR-13.9; ties break by who got there first
    // (FR-13.19), which the design leaves unsaid.
    'leaderboard_hint': {
      'tk': 'Balyňyz maşklardan we synaglardan gazanan netijeleriňiziň '
          'ortaça bahasyna görä hasaplanýar.',
      'ru': 'Ваш балл рассчитывается по среднему результату упражнений '
          'и экзаменов.',
      'en': 'Your score is calculated based on the average of the points '
          'you earn from exercises and exams.',
    },
    'you': {'tk': 'Siz', 'ru': 'Вы', 'en': 'You'},
    // Figma's "3 of 5". Turkmen marks the whole with the ablative and
    // puts it first, Russian uses a preposition — a whole pattern each.
    'progress_of': {'tk': '{m}-den {n}', 'ru': '{n} из {m}', 'en': '{n} of {m}'},
    'reorder_words': {
      'tk': 'Sözleri tertiple',
      'ru': 'Порядок слов',
      'en': 'Word order',
    },
    'reorder_instruction': {
      'tk': 'Dogry sözlem düzmek üçin sözleri tertipleşdiriň.',
      'ru': 'Расставьте слова, чтобы получилось правильное предложение.',
      'en': 'Arrange the words to make the correct sentence.',
    },
    'wrong': {'tk': 'Ýalňyş', 'ru': 'Неверно', 'en': 'Wrong'},
    'correct_body': {
      'tk': 'Berekella! Dowam ediň!',
      'ru': 'Отлично! Продолжайте!',
      'en': 'Great job! Keep going!',
    },
    // The verdict sheet after a wrong answer. Nothing promises the
    // question will return — the FR-12.7 re-queue is gone (FR-13.7).
    'made_mistakes': {
      'tk': 'Käbir ýalňyşlyklar goýberdiňiz.',
      'ru': 'Вы допустили ошибки.',
      'en': 'You made some mistakes.',
    },
    // An empty leaderboard is not missing content — nobody has scored.
    'no_ranking_yet': {
      'tk': 'Heniz hiç kim bal toplamady',
      'ru': 'Пока никто не набрал баллов',
      'en': 'No one has a score yet',
    },
    'settings': {'tk': 'Sazlamalar', 'ru': 'Настройки', 'en': 'Settings'},
    'unit_vocabulary': {
      'tk': 'Bölümiň sözlügi',
      'ru': 'Словарь юнита',
      'en': 'Unit Vocabulary',
    },
    'courses': {'tk': 'Okuwlar', 'ru': 'Курсы', 'en': 'Courses'},
    'lessons': {'tk': 'Sapaklar', 'ru': 'Уроки', 'en': 'Lessons'},
    'mark_completed': {
      'tk': 'Tamamlandy diý',
      'ru': 'Завершить',
      'en': 'Mark Completed',
    },
    'locked': {'tk': 'Ýapyk', 'ru': 'Закрыто', 'en': 'Locked'},
    // Nominative counting forms — "3 раздела", unlike [sections_noun]
    // whose genitive forms only fit after «из». The English 'Units' is
    // capitalised because its only surface is "13/36 Units completed".
    'sections_count': {
      'tk': 'bölüm',
      'ru': 'раздел|раздела|разделов',
      'en': 'section|sections',
    },
    'units_count': {
      'tk': 'bölüm',
      'ru': 'юнит|юнита|юнитов',
      'en': 'Unit|Units',
    },
    // Lower-case tail of "13 / 42 units completed".
    'completed_lc': {'tk': 'tamamlandy', 'ru': 'завершено', 'en': 'completed'},
    'feedback': {'tk': 'Teklip we bellik', 'ru': 'Отзыв', 'en': 'Feedback'},
    'contact_us': {
      'tk': 'Habarlaşmak',
      'ru': 'Связаться с нами',
      'en': 'Contact us',
    },
    'about_app': {
      'tk': 'Programma barada',
      'ru': 'О приложении',
      'en': 'About the App',
    },
    'version': {'tk': 'Wersiýa', 'ru': 'Версия', 'en': 'Version'},
    'hours_short': {'tk': 'sag', 'ru': 'ч', 'en': 'h'},
    'minutes_short': {'tk': 'min', 'ru': 'мин', 'en': 'min'},
    'days': {'tk': 'gün', 'ru': 'день|дня|дней', 'en': 'day|days'},
    'close': {'tk': 'Ýap', 'ru': 'Закрыть', 'en': 'Close'},
    'ask_your_teacher': {
      'tk': 'Mugallymyňyza ýüz tutuň. Merkeziň habarlaşmak maglumatlary '
          'entek programma goşulmady.',
      'ru': 'Обратитесь к своему преподавателю. Контакты центра пока '
          'не добавлены в приложение.',
      'en': 'Please ask your teacher. The centre\'s contact details are '
          'not in the app yet.',
    },

    /* ------------------------------------ redesign 2026-08 (FR-13.*) */

    'practice_modules': {
      'tk': 'Türgenleşik bölümleri',
      'ru': 'Практические модули',
      'en': 'Practice Modules',
    },
    // Fixed module captions from the home frames — sections carry no
    // description of their own.
    'grammar_module_caption': {
      'tk': 'Grammatika biliminizi berkidiň',
      'ru': 'Закрепите знания грамматики',
      'en': 'Practice your Grammar knowledge',
    },
    'listening_module_caption': {
      'tk': 'Diňläp düşünmegiňizi ösdüriň',
      'ru': 'Развивайте навык аудирования',
      'en': 'Grow up your listening skills',
    },
    'vocabulary_module_caption': {
      'tk': 'Täze sözleri öwreniň',
      'ru': 'Учите новые слова',
      'en': 'Learn new words',
    },
    'quiz_module_caption': {
      'tk': 'Öwrenenleriňizi barlaň',
      'ru': 'Проверьте, что вы выучили',
      'en': 'Test what you learned',
    },
    'unit_quiz': {'tk': 'Bölüm synagy', 'ru': 'Тест юнита', 'en': 'Unit Quiz'},
    'next_lesson': {
      'tk': 'Indiki sapak',
      'ru': 'Следующий урок',
      'en': 'Next Lesson',
    },
    'next_unit': {
      'tk': 'Indiki bölüm',
      'ru': 'Следующий юнит',
      'en': 'Next Unit',
    },
    'start': {'tk': 'Başla', 'ru': 'Начать', 'en': 'Start'},
    // Section-history modal (Figma `home-screen-section-history`; its
    // "AVARAGE" typo is not copied).
    'overall_average': {
      'tk': 'Umumy ortaça netije',
      'ru': 'Средний результат',
      'en': 'Overall average',
    },
    'history': {'tk': 'Taryh', 'ru': 'История', 'en': 'History'},
    'tries': {
      'tk': 'synanyşyk',
      'ru': 'попытка|попытки|попыток',
      'en': 'try|tries',
    },
    'average_hint': {
      'tk': 'Umumy ortaça netije ähli netijeleriňiziň jemini bölümi '
          'tamamlan sanyňyza bölmek bilen hasaplanýar.',
      'ru': 'Средний результат — это сумма ваших результатов, делённая '
          'на число завершённых прохождений раздела.',
      'en': 'Overall average is calculated by dividing the sum of your '
          'scores by the number of times you completed the section.',
    },
    'answers_correct_line': {
      'tk': '{n}/{m} jogap dogry',
      'ru': '{n}/{m} правильных ответов',
      'en': '{n}/{m} answers correct',
    },
    'today': {'tk': 'Şu gün', 'ru': 'Сегодня', 'en': 'Today'},
    // Read through list(), never plural() — twelve entries, not forms.
    'months_short': {
      'tk': 'Ýan|Few|Mart|Apr|Maý|Iýun|Iýul|Awg|Sen|Okt|Noý|Dek',
      'ru': 'янв|фев|мар|апр|мая|июн|июл|авг|сен|окт|ноя|дек',
      'en': 'Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec',
    },
    'grammar_guide': {
      'tk': 'Grammatika gollanmasy',
      'ru': 'Справочник грамматики',
      'en': 'Grammar Guide',
    },
    'example_sentences': {
      'tk': 'Mysal sözlemler',
      'ru': 'Примеры предложений',
      'en': 'Example Sentences',
    },
    // End screens (owner: agent C) — shared copy defined here so the
    // strings ship trilingual like everything else.
    'success_rate': {
      'tk': 'Netije',
      'ru': 'Результат',
      'en': 'Success rate',
    },
    'back_to_home': {
      'tk': 'Baş sahypa gaýt',
      'ru': 'На главную',
      'en': 'Back to Home',
    },
    // The End-session dialog (exercise back arrow / system back,
    // FR-13.5: leaving discards the run). Title and the red confirm
    // button carry the same copy in the frames.
    'end_session_title': {
      'tk': 'Sessiýany tamamla',
      'ru': 'Завершить сессию',
      'en': 'End session',
    },
    'end_session_body': {
      'tk': 'Sessiýany hakykatdan hem tamamlamak isleýärsiňizmi? '
          'Öňegidişiňiz ýatda saklanmaz.',
      'ru': 'Вы действительно хотите завершить сессию? Ваш прогресс '
          'не сохранится.',
      'en': "Do you really want to end this session. Your progress "
          "won't be saved.",
    },
    'end_session_confirm': {
      'tk': 'Sessiýany tamamla',
      'ru': 'Завершить сессию',
      'en': 'End session',
    },
    'retry': {
      'tk': 'Gaýtadan synanyş',
      'ru': 'Попробовать снова',
      'en': 'Try again',
    },
    // Exercise-header titles: the type names from the frames. The en
    // column is the design copy; tk/ru use nominal forms as titles.
    'type_multiple_choice': {
      'tk': 'Dogry jogaby saýlamak',
      'ru': 'Выбор ответа',
      'en': 'Multiple Choice',
    },
    'type_match_pairs': {
      'tk': 'Jübütleri birleşdirmek',
      'ru': 'Соединение пар',
      'en': 'Match Pairs',
    },
    'type_fill_blank': {
      'tk': 'Boşluklary doldurmak',
      'ru': 'Заполнение пропусков',
      'en': 'Fill the Blanks',
    },
    'type_fill_letter_space': {
      'tk': 'Harplary doldurmak',
      'ru': 'Пропущенные буквы',
      'en': 'Fill letter spaces',
    },
    // FR-4.20: an unsupported type is reported, never fabricated.
    'unsupported_type': {
      'tk': 'Goldanylmaýan maşk',
      'ru': 'Неподдерживаемое упражнение',
      'en': 'Unsupported exercise',
    },
    'unsupported_type_body': {
      'tk': 'Bu maşk görnüşi ({t}) programmanyň bu wersiýasynda '
          'goldanylmaýar.',
      'ru': 'Этот тип упражнения ({t}) не поддерживается этой версией '
          'приложения.',
      'en': 'This exercise type ({t}) is not supported by this version '
          'of the app.',
    },
    // End screens, exercise tier (good >= 80 / normal >= 50 / bad).
    'end_good_title': {
      'tk': 'Berekella!',
      'ru': 'Отличная работа!',
      'en': 'Great Job!',
    },
    'end_good_body': {
      'tk': 'Ajaýyp! Maşky örän gowy netije bilen tamamladyňyz.',
      'ru': 'Превосходно! Вы завершили упражнение с отличным результатом.',
      'en': 'Excellent work! You completed the exercise with a great '
          'result.',
    },
    'end_normal_title': {
      'tk': 'Gowy netije!',
      'ru': 'Хорошая работа!',
      'en': 'Good Work!',
    },
    'end_normal_body': {
      'tk': 'Gowy synanyşyk! Netijäňizi gowulandyrmak üçin türgenleşigi '
          'dowam ediň.',
      'ru': 'Неплохо! Продолжайте практиковаться, чтобы улучшить '
          'результат.',
      'en': 'Nice effort! Keep practicing to improve your result.',
    },
    'end_bad_title': {
      'tk': 'Türgenleşigi dowam ediň!',
      'ru': 'Продолжайте практиковаться!',
      'en': 'Keep Practicing!',
    },
    'end_bad_body': {
      'tk': 'Ruhdan düşmäň! Sapagy gaýtalap, maşky ýene synanyşyň.',
      'ru': 'Не сдавайтесь! Повторите урок и попробуйте упражнение ещё '
          'раз.',
      'en': "Don't give up! Review the lesson and try the exercise again.",
    },
    // End screens, quiz/exam tier (FR-13.4 quiz sections).
    'exam_good_title': {
      'tk': 'Ajaýyp netije!',
      'ru': 'Отличный результат!',
      'en': 'Excellent Result!',
    },
    'exam_good_body': {
      'tk': 'Gutlaýarys! Synagda ajaýyp netije gazandyňyz.',
      'ru': 'Поздравляем! Вы получили отличный результат на экзамене.',
      'en': 'Congratulations! You achieved an excellent score on the '
          'exam.',
    },
    'exam_normal_title': {
      'tk': 'Gowy netije!',
      'ru': 'Хороший результат!',
      'en': 'Good Result!',
    },
    'exam_normal_body': {
      'tk': 'Berekella! Synagdan geçdiňiz, ýöne kämilleşmäge mümkinçilik '
          'bar.',
      'ru': 'Молодец! Вы справились, но есть куда расти.',
      'en': "Well done! You passed, but there's still room to improve.",
    },
    'exam_bad_title': {
      'tk': 'Synanyşmagy dowam ediň!',
      'ru': 'Не останавливайтесь!',
      'en': 'Keep Trying!',
    },
    'exam_bad_body': {
      'tk': 'Alada etmäň! Sapaklary gaýtalap, synagy ýene synanyşyň.',
      'ru': 'Не переживайте! Повторите уроки и попробуйте экзамен снова.',
      'en': "Don't worry! Review your lessons and try the exam again.",
    },
  };

  /// Every defined key. Used by the completeness test — a missing key
  /// renders as its own name ("25 words" instead of "25 слов"), which is
  /// easy to ship and hard to notice.
  static Set<String> get definedKeys => _strings.keys.toSet();

  /// Languages this key has no translation for.
  static List<String> missingLanguages(String key) => supported
      .where((language) => (_strings[key]?[language] ?? '').isEmpty)
      .toList();

  String t(String key) => _strings[key]?[lang] ?? key;

  /// Counted nouns. Russian needs three forms and picks by the last one
  /// or two digits — "1 день", "2 дня", "5 дней" — and English needs
  /// two, so a single stored word produces wrong grammar for most
  /// counts. Turkmen does not inflect after a number, so it stores one
  /// form and returns it.
  ///
  /// Forms live in the table as `one|few|many` (ru) or `one|many` (en).
  String plural(String key, int n) {
    final forms = t(key).split('|');

    if (forms.length == 1) return forms.first;
    if (lang == 'en') return n.abs() == 1 ? forms.first : forms.last;
    if (lang != 'ru') return forms.first;

    final tens = n.abs() % 100;
    final ones = n.abs() % 10;

    if (ones == 1 && tens != 11) return forms[0];
    if (ones >= 2 && ones <= 4 && (tens < 12 || tens > 14)) return forms[1];

    return forms.length > 2 ? forms[2] : forms[1];
  }

  /// Whether a key stores per-count forms. Callers must reach those
  /// through [plural]; the completeness test enforces it.
  static bool isPlural(String key) =>
      (_strings[key]?['ru'] ?? '').contains('|');

  /// A `|`-separated table entry that is a list, not plural forms —
  /// the month names. Kept out of [plural]'s form-picking entirely.
  List<String> list(String key) => t(key).split('|');

  /// Fills `{name}` placeholders, so a translation can order its parts
  /// however its grammar requires.
  String f(String key, Map<String, Object> values) {
    var out = t(key);

    values.forEach((name, value) {
      out = out.replaceAll('{$name}', '$value');
    });

    return out;
  }

  /// Picks whichever of a server-supplied TK/RU/EN set matches the
  /// interface language. Content comes from the API already translated
  /// (FR-4.14); English titles are optional in the outline, so `en`
  /// falls back to Turkmen before Russian.
  String pick(String? tk, String? ru, [String? en]) => switch (lang) {
        'ru' => ru ?? tk ?? en,
        'en' => en ?? tk ?? ru,
        _ => tk ?? ru ?? en,
      } ??
      '';
}
