const TOKEN = '8495903763:AAGkcwcTLh2Wx4n6TFCzbCTjmISdLgE_mZc';
const CHAT_ID = '-5098739268';
const URL_API = `https://api.telegram.org/bot${TOKEN}/sendMessage`;

document.getElementById('storageForm').addEventListener('submit', function (e) {
     e.preventDefault();

     let message = 'Заявка хранение\n' + 'Имя: ' + this.name.value + '\n' + 'Email: ' + this.email.value + '\n' + 'Сообщение: ' + this.message.value;

     axios.post(URL_API, {
          chat_id: CHAT_ID,
          parse_mode: 'HTML',
          text: message,
     }).then((res) => {
          alert('Спасибо! Ваша заявка отправлена.');
     }).catch((err) => {
          console.warn(err);
          alert('Ошибка отправки заявки');
     }).finally(() => {
          console.log('Заявка отправлена');
          this.reset();
     });
});